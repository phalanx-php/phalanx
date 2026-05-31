<?php

declare(strict_types=1);

namespace Phalanx\Support;

use Closure;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Subscription;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Supervisor\TaskRunSnapshot;
use Phalanx\Supervisor\TransactionLease;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerTask;

trait ExecutionScopeDelegate
{
    public bool $isCancelled {
        get => $this->innerScope()->isCancelled;
    }

    public RuntimeContext $runtime {
        get => $this->innerScope()->runtime;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object
    {
        return $this->innerScope()->service($type);
    }

    public function currentRunId(): ?string
    {
        return $this->innerScope()->currentRunId();
    }

    public function currentRunSnapshot(): ?TaskRunSnapshot
    {
        return $this->innerScope()->currentRunSnapshot();
    }

    public function trace(): Trace
    {
        return $this->innerScope()->trace();
    }

    public function execute(Scopeable|Executable|Closure $task): mixed
    {
        return $this->innerScope()->execute($task);
    }

    public function executeFresh(Scopeable|Executable|Closure $task): mixed
    {
        return $this->innerScope()->executeFresh($task);
    }

    /** @return array<string|int, mixed> */
    public function concurrent(Scopeable|Executable|Closure ...$tasks): array
    {
        return $this->innerScope()->concurrent(...$tasks);
    }

    public function race(Scopeable|Executable|Closure ...$tasks): mixed
    {
        return $this->innerScope()->race(...$tasks);
    }

    public function any(Scopeable|Executable|Closure ...$tasks): mixed
    {
        return $this->innerScope()->any(...$tasks);
    }

    public function map(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        return $this->innerScope()->map($items, $fn, $limit, $onEach);
    }

    /** @return array<string|int, mixed> */
    public function series(Scopeable|Executable|Closure ...$tasks): array
    {
        return $this->innerScope()->series(...$tasks);
    }

    public function waterfall(Scopeable|Executable|Closure ...$tasks): mixed
    {
        return $this->innerScope()->waterfall(...$tasks);
    }

    public function settle(Scopeable|Executable|Closure ...$tasks): SettlementBag
    {
        return $this->innerScope()->settle(...$tasks);
    }

    public function timeout(float $seconds, Scopeable|Executable|Closure $task): mixed
    {
        return $this->innerScope()->timeout($seconds, $task);
    }

    public function retry(Scopeable|Executable|Closure $task, RetryPolicy $policy): mixed
    {
        return $this->innerScope()->retry($task, $policy);
    }

    public function delay(float $seconds): void
    {
        $this->innerScope()->delay($seconds);
    }

    public function periodic(float $interval, Closure $tick): Subscription
    {
        return $this->innerScope()->periodic($interval, $tick);
    }

    public function defer(Scopeable|Executable|Closure $task): void
    {
        $this->innerScope()->defer($task);
    }

    public function singleflight(string $key, Scopeable|Executable|Closure $task): mixed
    {
        return $this->innerScope()->singleflight($key, $task);
    }

    public function inWorker(WorkerTask $task): mixed
    {
        return $this->innerScope()->inWorker($task);
    }

    /** @return array<string|int, mixed> */
    public function parallel(WorkerTask ...$tasks): array
    {
        return $this->innerScope()->parallel(...$tasks);
    }

    public function settleParallel(WorkerTask ...$tasks): SettlementBag
    {
        return $this->innerScope()->settleParallel(...$tasks);
    }

    public function mapParallel(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        return $this->innerScope()->mapParallel($items, $fn, $limit, $onEach);
    }

    public function go(Closure $fn, ?string $name = null): TaskHandle
    {
        return $this->innerScope()->go($fn, $name);
    }

    public function call(Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        return $this->innerScope()->call($fn, $waitReason);
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

    public function transaction(TransactionLease $lease, Closure $body): mixed
    {
        return $this->innerScope()->transaction($lease, $body);
    }

    abstract protected function innerScope(): ExecutionScope;
}
