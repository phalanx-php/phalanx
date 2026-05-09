<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Worker\WorkerTask;

interface TaskExecutor
{
    /**
     * @param Scopeable|Executable|Closure ...$tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(Scopeable|Executable|Closure ...$tasks): array;

    /** @param Scopeable|Executable|Closure ...$tasks */
    public function race(Scopeable|Executable|Closure ...$tasks): mixed;

    /** @param Scopeable|Executable|Closure ...$tasks */
    public function any(Scopeable|Executable|Closure ...$tasks): mixed;

    /**
     * @template TItem
     * @param iterable<string|int, TItem> $items
     * @param Closure(TItem): (Scopeable|Executable|Closure) $fn
     * @param ?Closure(string|int, mixed): void $onEach
     * @return array<string|int, mixed>
     */
    public function map(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array;

    /**
     * @param Scopeable|Executable|Closure ...$tasks
     * @return array<string|int, mixed>
     */
    public function series(Scopeable|Executable|Closure ...$tasks): array;

    /** @param Scopeable|Executable|Closure ...$tasks */
    public function waterfall(Scopeable|Executable|Closure ...$tasks): mixed;

    /** @param Scopeable|Executable|Closure ...$tasks */
    public function settle(Scopeable|Executable|Closure ...$tasks): SettlementBag;

    public function timeout(float $seconds, Scopeable|Executable|Closure $task): mixed;

    public function retry(Scopeable|Executable|Closure $task, RetryPolicy $policy): mixed;

    public function delay(float $seconds): void;

    /**
     * Run $tick every $interval seconds on a supervised coroutine bound
     * to the current scope. The returned Subscription stops the timer when
     * cancel() is called; the timer is also cleared automatically when the
     * scope is disposed.
     *
     * The tick closure must be static — non-static closures capture $this
     * and create reference cycles in long-running processes.
     *
     * @param Closure(): void $tick
     */
    public function periodic(float $interval, Closure $tick): Subscription;

    public function defer(Scopeable|Executable|Closure $task): void;

    public function singleflight(string $key, Scopeable|Executable|Closure $task): mixed;

    public function inWorker(WorkerTask $task): mixed;

    /**
     * @param WorkerTask ...$tasks
     * @return array<string|int, mixed>
     */
    public function parallel(WorkerTask ...$tasks): array;

    /** @param WorkerTask ...$tasks */
    public function settleParallel(WorkerTask ...$tasks): SettlementBag;

    /**
     * @template TItem
     * @param iterable<string|int, TItem> $items
     * @param Closure(TItem): WorkerTask $fn
     * @param ?Closure(string|int, mixed): void $onEach
     * @return array<string|int, mixed>
     */
    public function mapParallel(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array;

    /**
     * Spawn a supervisor-tracked background task and return immediately.
     * Use for fire-and-forget work whose result the caller does not await.
     *
     * Unlike defer(), the spawned task is registered in the supervisor's
     * ledger as a concurrent child of the current run — visible in the
     * task tree, cancellable via the returned TaskRun's cancellation token,
     * and bounded by the parent scope's lifetime.
     *
     * Errors raised inside the body are caught and emitted as
     * PHX-SPAWN-001 trace events; they do NOT propagate to the parent
     * coroutine. This is intentional — go() is an error boundary so a
     * crashing background task can never tear down the worker process or
     * surprise unrelated code paths.
     *
     * If the parent scope is disposed while a go() task is still running,
     * PHX-SPAWN-002 is emitted and the task is force-cancelled.
     *
     * Cancel an in-flight spawn via:
     *   $run = $scope->go(static fn() => ...);
     *   $run->cancellation->cancel();
     */
    public function go(Closure $fn, ?string $name = null): TaskRun;
}
