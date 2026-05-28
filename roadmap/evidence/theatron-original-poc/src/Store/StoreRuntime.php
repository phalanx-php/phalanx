<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use Closure;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Scope\ExecutionScope;
use ReflectionFunction;
use Throwable;

final class StoreRuntime
{
    private ?Channel $writes = null;
    private bool $started = false;
    private int $nextSubscriberId = 0;
    private int $writerCid = -1;

    /** @var array<class-string<Slice>, SliceTable|MemorySliceTable> */
    private array $tables = [];

    /** @var array<class-string<Slice>, array<int, Closure(): void>> */
    private array $subscribers = [];

    /** @param list<class-string<Slice>> $slices */
    public function __construct(
        private(set) string $name,
        private(set) StoreStrategy $strategy,
        array $slices,
    ) {
        if ($this->strategy === StoreStrategy::Parallel) {
            throw UnsupportedStoreStrategy::strategy($this->strategy);
        }

        foreach ($slices as $slice) {
            $this->tables[$slice] = $this->strategy === StoreStrategy::Memory
                ? new MemorySliceTable($slice)
                : new SliceTable(SliceSchema::from($slice));
        }
    }

    public function start(ExecutionScope $scope): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->writes = new Channel(1024);
        $runtime = $this;
        $scope->go(static function (ExecutionScope $scope) use ($runtime): void {
            $runtime->runWriter($scope);
        }, "theatron.store.{$this->name}.writer");

        $scope->onDispose(static function () use ($runtime): void {
            $runtime->stop();
        });
    }

    /** @param class-string<Slice> $slice */
    public function read(string $slice): Slice
    {
        return $this->table($slice)->read();
    }

    /**
     * @param class-string<Slice> $slice
     * @param Closure(Slice): Slice $update
     */
    public function update(string $slice, Closure $update): Slice
    {
        if ($this->writes === null) {
            throw StoreNotStarted::writer();
        }

        if (Coroutine::getCid() === $this->writerCid) {
            throw new StoreException('Store writes cannot be nested inside store subscriber callbacks.');
        }

        $reply = new Channel(1);
        if (!$this->writes->push(new StoreMutation($slice, $update, $reply), 1.0)) {
            throw new StoreException("Unable to queue store write for {$slice}.");
        }

        $result = $reply->pop();
        if (!is_array($result)) {
            throw new StoreException("Store write for {$slice} did not acknowledge.");
        }

        if ($result[0] === false) {
            $error = $result[1] ?? new StoreException("Store write for {$slice} failed.");
            if ($error instanceof Throwable) {
                throw $error;
            }

            throw new StoreException((string) $error);
        }

        $value = $result[1] ?? null;
        if (!$value instanceof Slice) {
            throw new StoreException("Store write for {$slice} returned an invalid value.");
        }

        return $value;
    }

    /** @param class-string<Slice> $slice */
    public function subscribe(string $slice, Closure $subscriber): StoreSubscription
    {
        $this->table($slice);
        if (!new ReflectionFunction($subscriber)->isStatic()) {
            throw new StoreException('Store subscribers must be static closures.');
        }

        $id = $this->nextSubscriberId++;
        $this->subscribers[$slice][$id] = $subscriber;
        $runtime = $this;

        return new StoreSubscription(static function () use ($runtime, $slice, $id): void {
            unset($runtime->subscribers[$slice][$id]);
        });
    }

    public function stop(): void
    {
        $this->started = false;
        $this->writes?->close();
        $this->writes = null;
    }

    private function runWriter(ExecutionScope $scope): void
    {
        $this->writerCid = Coroutine::getCid();

        try {
            while ($this->started && !$scope->isCancelled) {
                $message = $this->writes?->pop(0.05);
                if (!$message instanceof StoreMutation) {
                    continue;
                }

                $this->apply($message);
            }
        } finally {
            $this->writerCid = -1;
        }
    }

    private function apply(StoreMutation $mutation): void
    {
        try {
            $table = $this->table($mutation->slice);
            $current = $table->read();
            $next = ($mutation->update)($current);
            if ($table->matches($current, $next)) {
                $mutation->reply->push([true, $next]);

                return;
            }

            $table->write($next);
            $this->notify($mutation->slice);
            $mutation->reply->push([true, $next]);
        } catch (Throwable $e) {
            $mutation->reply->push([false, $e]);
        }
    }

    /** @param class-string<Slice> $slice */
    private function notify(string $slice): void
    {
        foreach ($this->subscribers[$slice] ?? [] as $subscriber) {
            $subscriber();
        }
    }

    /** @param class-string<Slice> $slice */
    private function table(string $slice): SliceTable|MemorySliceTable
    {
        return $this->tables[$slice] ?? throw UnknownSlice::class($slice);
    }
}
