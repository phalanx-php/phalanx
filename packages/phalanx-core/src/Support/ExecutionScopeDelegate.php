<?php

declare(strict_types=1);

namespace Phalanx\Support;

use Closure;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\ExecutionScope;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;

/**
 * Delegates all ExecutionScope methods to an inner scope.
 *
 * Users must implement innerScope(): ExecutionScope.
 * Override withAttribute() in the consuming class to preserve decorator identity.
 */
trait ExecutionScopeDelegate
{
    public bool $isCancelled {
        get => $this->innerScope()->isCancelled;
    }

    public function service(string $type): object
    {
        return $this->innerScope()->service($type);
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->innerScope()->attribute($key, $default);
    }

    public function execute(Scopeable|Executable $task): mixed
    {
        return $this->innerScope()->execute($task);
    }

    public function executeFresh(Scopeable|Executable $task): mixed
    {
        return $this->innerScope()->executeFresh($task);
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function concurrent(array $tasks): array
    {
        return $this->innerScope()->concurrent($tasks);
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function race(array $tasks): mixed
    {
        return $this->innerScope()->race($tasks);
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function any(array $tasks): mixed
    {
        return $this->innerScope()->any($tasks);
    }

    public function map(array $items, Closure $fn, int $limit = 10): array
    {
        return $this->innerScope()->map($items, $fn, $limit);
    }

    /** @param list<Scopeable|Executable> $tasks */
    public function series(array $tasks): array
    {
        return $this->innerScope()->series($tasks);
    }

    /** @param list<Scopeable|Executable> $tasks */
    public function waterfall(array $tasks): mixed
    {
        return $this->innerScope()->waterfall($tasks);
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function settle(array $tasks): SettlementBag
    {
        return $this->innerScope()->settle($tasks);
    }

    public function timeout(float $seconds, Scopeable|Executable $task): mixed
    {
        return $this->innerScope()->timeout($seconds, $task);
    }

    public function retry(Scopeable|Executable $task, RetryPolicy $policy): mixed
    {
        return $this->innerScope()->retry($task, $policy);
    }

    public function delay(float $seconds): void
    {
        $this->innerScope()->delay($seconds);
    }

    public function defer(Scopeable|Executable $task): void
    {
        $this->innerScope()->defer($task);
    }

    public function throwIfCancelled(): void
    {
        $this->innerScope()->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->innerScope()->cancellation();
    }

    public function onDispose(Closure $callback): void
    {
        $this->innerScope()->onDispose($callback);
    }

    public function dispose(): void
    {
        $this->innerScope()->dispose();
    }

    public function trace(): Trace
    {
        return $this->innerScope()->trace();
    }

    public function inWorker(Scopeable|Executable $task): mixed
    {
        return $this->innerScope()->inWorker($task);
    }

    abstract protected function innerScope(): ExecutionScope;
}
