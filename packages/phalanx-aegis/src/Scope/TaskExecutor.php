<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Closure;

interface TaskExecutor
{
    /**
     * @param array<string|int, Scopeable|Executable|Closure> $tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(array $tasks): array;

    /** @param array<string|int, Scopeable|Executable|Closure> $tasks */
    public function race(array $tasks): mixed;

    /** @param array<string|int, Scopeable|Executable|Closure> $tasks */
    public function any(array $tasks): mixed;

    /**
     * @template TItem
     * @param iterable<string|int, TItem> $items
     * @param Closure(TItem): (Scopeable|Executable|Closure) $fn
     * @param ?Closure(string|int, mixed): void $onEach
     * @return array<string|int, mixed>
     */
    public function map(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array;

    /**
     * @param array<int, Scopeable|Executable|Closure> $tasks
     * @return array<int, mixed>
     */
    public function series(array $tasks): array;

    /** @param array<int, Scopeable|Executable|Closure> $tasks */
    public function waterfall(array $tasks): mixed;

    /** @param array<string|int, Scopeable|Executable|Closure> $tasks */
    public function settle(array $tasks): SettlementBag;

    public function timeout(float $seconds, Scopeable|Executable|Closure $task): mixed;

    public function retry(Scopeable|Executable|Closure $task, RetryPolicy $policy): mixed;

    public function delay(float $seconds): void;

    public function defer(Scopeable|Executable|Closure $task): void;

    public function singleflight(string $key, Scopeable|Executable|Closure $task): mixed;

    public function inWorker(Scopeable|Executable|Closure $task): mixed;
}
