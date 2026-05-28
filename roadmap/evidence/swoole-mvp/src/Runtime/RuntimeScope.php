<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Runtime;

use OpenSwoole\Coroutine as Co;
use OpenSwoole\Coroutine\Channel;
use Phalanx\Swoole\Mvp\Scope\CompositionScope;
use Phalanx\Swoole\Mvp\Scope\PureScope;
use Phalanx\Swoole\Mvp\Scope\ReadScope;
use Phalanx\Swoole\Mvp\Scope\WriteScope;
use Phalanx\Swoole\Mvp\Service\Container;
use Phalanx\Swoole\Mvp\Service\ResourceDescriptor;

final class RuntimeScope implements PureScope, ReadScope, WriteScope, CompositionScope
{
    /** @var list<\Closure(): void> */
    private array $disposers = [];

    private bool $disposed = false;

    private bool $inTransaction = false;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly Container $container,
        private readonly TaskMetadata $taskMetadata,
        private readonly int $ownerCid,
    ) {}

    public function isCancelled(): bool
    {
        return Co::isCanceled();
    }

    public function throwIfCancelled(): void
    {
        if (Co::isCanceled()) {
            throw new CancellationException("Coroutine {$this->ownerCid} cancelled.");
        }
    }

    public function onDispose(\Closure $callback): void
    {
        if ($this->disposed) {
            $callback();
            return;
        }
        $this->disposers[] = $callback;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;
        for ($i = count($this->disposers) - 1; $i >= 0; $i--) {
            try {
                ($this->disposers[$i])();
            } catch (\Throwable) {
            }
        }
        $this->disposers = [];
    }

    public function awaitChannel(Channel $channel): mixed
    {
        $result = $channel->pop();
        if ($result === false && Co::isCanceled()) {
            throw new CancellationException('awaitChannel cancelled.');
        }
        return $result;
    }

    public function awaitFuture(Future $future): mixed
    {
        return $future->wait();
    }

    public function use(string $resource): object
    {
        $profile = $this->taskMetadata->profile;
        if ($profile === TaskMetadata::PROFILE_READS) {
            if (! in_array($resource, $this->taskMetadata->reads, true)) {
                throw new CapabilityViolation(sprintf(
                    'Task %s did not declare reads on %s.',
                    $this->taskMetadata->class,
                    $resource,
                ));
            }
        } elseif ($profile === TaskMetadata::PROFILE_WRITES) {
            $allowed = isset($this->taskMetadata->writes[$resource])
                || in_array($resource, $this->taskMetadata->reads, true);
            if (! $allowed) {
                throw new CapabilityViolation(sprintf(
                    'Task %s did not declare writes/reads on %s.',
                    $this->taskMetadata->class,
                    $resource,
                ));
            }
        } else {
            throw new CapabilityViolation(sprintf(
                'use() not available in %s scope.',
                $profile,
            ));
        }

        if ($this->inTransaction) {
            $descriptor = $this->container->descriptors()[$resource] ?? null;
            if ($descriptor === null || ! $descriptor->transactionSafe) {
                throw new CapabilityViolation(sprintf(
                    'Resource %s is not transactionSafe; cannot be used inside transaction().',
                    $resource,
                ));
            }
        }

        return $this->container->get($resource);
    }

    public function transaction(\Closure $body): mixed
    {
        if ($this->taskMetadata->profile !== TaskMetadata::PROFILE_WRITES) {
            throw new CapabilityViolation('transaction() requires Writes profile.');
        }
        $previous = $this->inTransaction;
        $this->inTransaction = true;
        try {
            return $body($this);
        } finally {
            $this->inTransaction = $previous;
        }
    }

    public function run(object $task): mixed
    {
        $this->requireComposition('run');
        return $this->dispatcher->dispatch($task);
    }

    public function runIsolated(object $task): mixed
    {
        $this->requireComposition('runIsolated');
        return $this->dispatcher->dispatchIsolated($task);
    }

    public function parallel(array $tasks): array
    {
        $this->requireComposition('parallel');
        return $this->dispatcher->parallel($tasks);
    }

    public function firstOf(array $tasks): mixed
    {
        $this->requireComposition('firstOf');
        return $this->dispatcher->firstOf($tasks);
    }

    public function all(iterable $items, \Closure $factory): array
    {
        $this->requireComposition('all');
        return $this->dispatcher->all($items, $factory);
    }

    public function runDynamic(string $taskClass, string $reason): mixed
    {
        $this->requireComposition('runDynamic');
        if (! class_exists($taskClass)) {
            throw new CapabilityViolation("runDynamic: unknown class {$taskClass}.");
        }
        $task = new $taskClass();
        return $this->dispatcher->dispatch($task);
    }

    private function requireComposition(string $verb): void
    {
        if ($this->taskMetadata->profile !== TaskMetadata::PROFILE_COMPOSES) {
            throw new CapabilityViolation(sprintf(
                '%s() not available in %s scope (only Composes).',
                $verb,
                $this->taskMetadata->profile,
            ));
        }
    }
}
