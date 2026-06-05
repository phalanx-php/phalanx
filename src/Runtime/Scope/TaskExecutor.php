<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Mark\Mark;
use Phalanx\Recovery\RecoveryPlan;
use Phalanx\Scheduling\ScheduleBuilder;
use Phalanx\Supervisor\TaskHandle;
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

    public function timeout(Mark $duration, Scopeable|Executable|Closure $task): mixed;

    public function retry(Scopeable|Executable|Closure $task, RecoveryPlan $plan): mixed;

    public function delay(Mark $duration): void;

    /**
     * @param Closure(): void $tick
     */
    public function periodic(Mark $interval, Closure $tick): Subscription;

    public function schedule(): ScheduleBuilder;

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
     * @param Closure(ExecutionScope): mixed $fn
     */
    public function go(Closure $fn, ?string $name = null): TaskHandle;
}
