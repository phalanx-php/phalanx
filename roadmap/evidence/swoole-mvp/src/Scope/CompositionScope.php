<?php

declare(strict_types=1);

namespace Phalanx\Swoole\Mvp\Scope;

interface CompositionScope extends Cancellable, Disposable, Suspendable
{
    public function run(object $task): mixed;

    public function runIsolated(object $task): mixed;

    /**
     * @param array<int|string, object> $tasks
     * @return array<int|string, mixed>
     */
    public function parallel(array $tasks): array;

    /**
     * @param array<int|string, object> $tasks
     */
    public function firstOf(array $tasks): mixed;

    /**
     * @template TIn
     * @param iterable<TIn> $items
     * @param \Closure(TIn): object $factory
     * @return list<mixed>
     */
    public function all(iterable $items, \Closure $factory): array;

    /**
     * @param class-string $taskClass
     */
    public function runDynamic(string $taskClass, string $reason): mixed;
}
