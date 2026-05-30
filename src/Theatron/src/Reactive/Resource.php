<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactive;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Supervisor\TaskHandle;
use ReflectionFunction;
use RuntimeException;
use Throwable;
use WeakReference;

final class Resource
{
    /** tracked: records Resource reads for render/computed dependencies. */
    public bool $loading {
        get => $this->readLoading();
    }

    /** tracked: records Resource reads for render/computed dependencies. */
    public mixed $value {
        get => $this->readValue();
    }

    /** tracked: records Resource reads for render/computed dependencies. */
    public ?Throwable $error {
        get => $this->readError();
    }

    /** tracked: records Resource reads for render/computed dependencies. */
    public bool $ok {
        get => $this->readOk();
    }

    /** tracked: records Resource reads for render/computed dependencies. */
    public string $buffer {
        get => $this->readBuffer();
    }

    public int $subscriberCount {
        get => $this->countSubscribers();
    }

    private bool $disposed = false;
    private int $generation = 0;
    private int $nextSubscriberId = 0;
    private bool $loadingValue = false;
    private mixed $storedValue = null;
    private ?Throwable $storedError = null;
    private bool $okValue = false;
    private string $bufferValue = '';

    private ?TaskHandle $currentTask = null;

    /** @var array<int, Closure(): void> */
    private array $subscribers = [];

    public function __construct(
        private Closure $fetcher,
        private ?TaskExecutor $executor = null,
        private ?Closure $onDirty = null,
    ) {
        if (!new ReflectionFunction($fetcher)->isStatic()) {
            throw new RuntimeException('Resource fetcher must be a static closure.');
        }

        if ($onDirty !== null && !new ReflectionFunction($onDirty)->isStatic()) {
            throw new RuntimeException('Resource onDirty callback must be a static closure.');
        }
    }

    public function refresh(mixed $key = null): void
    {
        if ($this->disposed) {
            return;
        }

        $this->cancelCurrentTask();
        $this->loadingValue = true;
        $this->storedError = null;
        $this->bufferValue = '';
        $this->notify();

        $gen = ++$this->generation;
        $fetcher = $this->fetcher;

        if ($this->executor !== null) {
            $this->fetchAsync($gen, $key, $fetcher);
        } else {
            $this->fetchSync($gen, $key, $fetcher);
        }
    }

    public function stream(mixed $key = null): void
    {
        if ($this->disposed) {
            return;
        }

        $this->cancelCurrentTask();
        $this->loadingValue = true;
        $this->storedError = null;
        $this->bufferValue = '';
        $this->notify();

        $gen = ++$this->generation;
        $fetcher = $this->fetcher;

        if ($this->executor !== null) {
            $this->streamAsync($gen, $key, $fetcher);
        } else {
            $this->streamSync($gen, $key, $fetcher);
        }
    }

    public function subscribe(Closure $subscriber): ResourceSubscription
    {
        if ($this->disposed) {
            throw new RuntimeException('Cannot subscribe to a disposed resource.');
        }

        if (!new ReflectionFunction($subscriber)->isStatic()) {
            throw new RuntimeException('Resource subscribers must be static closures.');
        }

        $id = $this->nextSubscriberId++;
        $this->subscribers[$id] = $subscriber;

        return new ResourceSubscription($this, $id);
    }

    public function unsubscribe(int $id): void
    {
        unset($this->subscribers[$id]);
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->cancelCurrentTask();
        $this->disposed = true;
        $this->loadingValue = false;
        $this->generation++;
        $this->subscribers = [];
    }

    private function fetchSync(int $gen, mixed $key, Closure $fetcher): void
    {
        try {
            $result = $fetcher($key);
        } catch (Cancelled $e) {
            $this->handleCancelled($gen);

            throw $e;
        } catch (Throwable $e) {
            if ($this->generation === $gen && !$this->disposed) {
                $this->storedError = $e;
                $this->loadingValue = false;
                $this->notify();
            }

            return;
        }

        if ($this->generation !== $gen || $this->disposed) {
            return;
        }

        $this->storedValue = $result;
        $this->okValue = true;
        $this->loadingValue = false;
        $this->notify();
    }

    private function fetchAsync(int $gen, mixed $key, Closure $fetcher): void
    {
        if ($this->executor === null) {
            return;
        }

        $weakSelf = WeakReference::create($this);

        $this->currentTask = $this->executor->go(static function () use ($weakSelf, $gen, $key, $fetcher): void {
            try {
                $result = $fetcher($key);
            } catch (Cancelled $e) {
                $weakSelf->get()?->handleCancelled($gen);

                throw $e;
            } catch (Throwable $e) {
                $self = $weakSelf->get();
                if ($self !== null && $self->generation === $gen && !$self->disposed) {
                    $self->storedError = $e;
                    $self->loadingValue = false;
                    $self->currentTask = null;
                    $self->notify();
                }

                return;
            }

            $self = $weakSelf->get();
            if ($self === null || $self->generation !== $gen || $self->disposed) {
                return;
            }

            $self->storedValue = $result;
            $self->okValue = true;
            $self->loadingValue = false;
            $self->currentTask = null;
            $self->notify();
        }, 'theatron.resource.refresh');
    }

    private function streamSync(int $gen, mixed $key, Closure $fetcher): void
    {
        try {
            $this->consumeStream($gen, $fetcher($key));
        } catch (Cancelled $e) {
            $this->handleCancelled($gen);

            throw $e;
        } catch (Throwable $e) {
            if ($this->generation === $gen && !$this->disposed) {
                $this->storedError = $e;
                $this->loadingValue = false;
                $this->notify();
            }

            return;
        }

        if ($this->generation !== $gen || $this->disposed) {
            return;
        }

        $this->storedValue = $this->bufferValue;
        $this->okValue = true;
        $this->loadingValue = false;
        $this->notify();
    }

    private function streamAsync(int $gen, mixed $key, Closure $fetcher): void
    {
        if ($this->executor === null) {
            return;
        }

        $weakSelf = WeakReference::create($this);

        $this->currentTask = $this->executor->go(static function () use ($weakSelf, $gen, $key, $fetcher): void {
            $self = $weakSelf->get();
            if ($self === null || $self->generation !== $gen || $self->disposed) {
                return;
            }

            try {
                $self->consumeStream($gen, $fetcher($key));
            } catch (Cancelled $e) {
                $weakSelf->get()?->handleCancelled($gen);

                throw $e;
            } catch (Throwable $e) {
                $self = $weakSelf->get();
                if ($self !== null && $self->generation === $gen && !$self->disposed) {
                    $self->storedError = $e;
                    $self->loadingValue = false;
                    $self->currentTask = null;
                    $self->notify();
                }

                return;
            }

            $self = $weakSelf->get();
            if ($self === null || $self->generation !== $gen || $self->disposed) {
                return;
            }

            $self->storedValue = $self->bufferValue;
            $self->okValue = true;
            $self->loadingValue = false;
            $self->currentTask = null;
            $self->notify();
        }, 'theatron.resource.stream');
    }

    private function consumeStream(int $gen, mixed $stream): void
    {
        if (!is_iterable($stream)) {
            throw new RuntimeException('Resource stream fetcher must return an iterable.');
        }

        foreach ($stream as $chunk) {
            if ($this->generation !== $gen || $this->disposed) {
                return;
            }

            if (!is_string($chunk)) {
                throw new RuntimeException('Resource stream chunks must be strings.');
            }

            $this->bufferValue .= $chunk;
            $this->notify();
        }
    }

    private function readLoading(): bool
    {
        Tracker::recordAccess($this);

        return $this->loadingValue;
    }

    private function readValue(): mixed
    {
        Tracker::recordAccess($this);

        return $this->storedValue;
    }

    private function readError(): ?Throwable
    {
        Tracker::recordAccess($this);

        return $this->storedError;
    }

    private function readOk(): bool
    {
        Tracker::recordAccess($this);

        return $this->okValue;
    }

    private function readBuffer(): string
    {
        Tracker::recordAccess($this);

        return $this->bufferValue;
    }

    private function countSubscribers(): int
    {
        return count($this->subscribers);
    }

    private function cancelCurrentTask(): void
    {
        if ($this->currentTask === null) {
            return;
        }

        $task = $this->currentTask;
        $this->currentTask = null;
        $task->cancel();
    }

    private function handleCancelled(int $gen): void
    {
        if ($this->generation !== $gen || $this->disposed) {
            return;
        }

        $this->loadingValue = false;
        $this->currentTask = null;
        $this->notify();
    }

    private function notify(): void
    {
        $this->onDirty?->__invoke();

        $snapshot = $this->subscribers;

        foreach ($snapshot as $subscriber) {
            $subscriber();
        }
    }
}
