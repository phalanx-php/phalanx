<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

/**
 * Execution scope with concurrency primitives, cancellation, and disposal.
 *
 * Extends the generic Scope with execution capabilities. Used by code that
 * orchestrates tasks: LazySequence, Reduce, concurrent handlers.
 *
 * Handler `fn` closures receive this type for full execution control.
 */
interface ExecutionScope extends Scope, StreamContext
{
    public bool $isCancelled { get; }

    public function execute(Scopeable|Executable $task): mixed;

    public function executeFresh(Scopeable|Executable $task): mixed;

    /**
     * @param array<string|int, Scopeable|Executable> $tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(array $tasks): array;

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function race(array $tasks): mixed;

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function any(array $tasks): mixed;

    /**
     * @template T
     * @param array<string|int, T> $items
     * @param Closure(T): (Scopeable|Executable) $fn
     * @param int $limit
     * @return array<string|int, mixed>
     */
    public function map(array $items, Closure $fn, int $limit = 10): array;

    /**
     * @param list<Scopeable|Executable> $tasks
     * @return list<mixed>
     */
    public function series(array $tasks): array;

    /** @param list<Scopeable|Executable> $tasks */
    public function waterfall(array $tasks): mixed;

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function settle(array $tasks): SettlementBag;

    public function timeout(float $seconds, Scopeable|Executable $task): mixed;

    public function retry(Scopeable|Executable $task, RetryPolicy $policy): mixed;

    public function delay(float $seconds): void;

    public function defer(Scopeable|Executable $task): void;

    public function cancellation(): CancellationToken;

    public function withAttribute(string $key, mixed $value): ExecutionScope;

    public function onDispose(Closure $callback): void;

    public function dispose(): void;

    /**
     * Execute a task in a worker process.
     *
     * The task must be serializable (invokable class with serializable constructor args).
     * Services accessed via $scope->service() are proxied back to the parent process.
     */
    public function inWorker(Scopeable|Executable $task): mixed;
}
