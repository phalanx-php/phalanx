<?php

declare(strict_types=1);

namespace Convoy\Service;

use Closure;
use Convoy\Concurrency\CancellationToken;
use Convoy\Concurrency\RetryPolicy;
use Convoy\Concurrency\SettlementBag;
use Convoy\ExecutionScope;
use Convoy\Task\Executable;
use Convoy\Task\Scopeable;
use Convoy\Trace\Trace;
use RuntimeException;

final class DeferredScope implements ExecutionScope
{
    public bool $isCancelled {
        get => $this->scope()->isCancelled;
    }

    public function service(string $type): object
    {
        return $this->scope()->service($type);
    }

    public function execute(Scopeable|Executable $task): mixed
    {
        return $this->scope()->execute($task);
    }

    public function executeFresh(Scopeable|Executable $task): mixed
    {
        return $this->scope()->executeFresh($task);
    }

    /**
     * @param array<string|int, Scopeable|Executable> $tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(array $tasks): array
    {
        return $this->scope()->concurrent($tasks);
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function race(array $tasks): mixed
    {
        return $this->scope()->race($tasks);
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function any(array $tasks): mixed
    {
        return $this->scope()->any($tasks);
    }

    /**
     * @param array<string|int, mixed> $items
     * @return array<string|int, mixed>
     */
    public function map(array $items, Closure $fn, int $limit = 10): array
    {
        return $this->scope()->map($items, $fn, $limit);
    }

    /**
     * @param list<Scopeable|Executable> $tasks
     * @return list<mixed>
     */
    public function series(array $tasks): array
    {
        return $this->scope()->series($tasks);
    }

    public function waterfall(array $tasks): mixed
    {
        return $this->scope()->waterfall($tasks);
    }

    public function delay(float $seconds): void
    {
        $this->scope()->delay($seconds);
    }

    public function retry(Scopeable|Executable $task, RetryPolicy $policy): mixed
    {
        return $this->scope()->retry($task, $policy);
    }

    public function settle(array $tasks): SettlementBag
    {
        return $this->scope()->settle($tasks);
    }

    public function timeout(float $seconds, Scopeable|Executable $task): mixed
    {
        return $this->scope()->timeout($seconds, $task);
    }

    public function throwIfCancelled(): void
    {
        $this->scope()->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->scope()->cancellation();
    }

    public function onDispose(Closure $callback): void
    {
        $this->scope()->onDispose($callback);
    }

    public function dispose(): void
    {
        $this->scope()->dispose();
    }

    public function trace(): Trace
    {
        return $this->scope()->trace();
    }

    public function defer(Scopeable|Executable $task): void
    {
        $this->scope()->defer($task);
    }

    public function withAttribute(string $key, mixed $value): ExecutionScope
    {
        return $this->scope()->withAttribute($key, $value);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->scope()->attribute($key, $default);
    }

    public function inWorker(Scopeable|Executable $task): mixed
    {
        return $this->scope()->inWorker($task);
    }

    private function scope(): ExecutionScope
    {
        return FiberScopeRegistry::current()
            ?? throw new RuntimeException('No scope registered for current fiber context');
    }
}
