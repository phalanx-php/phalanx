<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;

interface TaskExecutor
{
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
     * @param iterable<string|int, T> $items
     * @param Closure(T): (Scopeable|Executable) $fn
     * @param int $limit
     * @param Closure(mixed, string|int): void|null $onEach Called as each item settles, before map() returns.
     * @return array<string|int, mixed>
     */
    public function map(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array;

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

    public function singleflight(string $key, Scopeable|Executable $task): mixed;

    public function inWorker(Scopeable|Executable $task): mixed;
}
