<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\Settlement;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Service\CompiledService;
use Phalanx\Service\DeferredScope;
use Phalanx\Service\FiberScopeRegistry;
use Phalanx\Service\LazyFactory;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceGraph;
use Phalanx\Support\ClassNames;
use Phalanx\Support\ErrorHandler;
use Phalanx\Task\Executable;
use Phalanx\Task\HasPriority;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\TaskConfig;
use Phalanx\Task\Traceable;
use Phalanx\Task\UsesPool;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use React\Promise\Deferred;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;
use function React\Promise\all;
use function React\Promise\any;
use function React\Promise\race;

final class ExecutionLifecycleScope implements ExecutionScope
{
    private bool $disposed = false;

    /** @var list<string> */
    private array $creationOrder = [];

    /** @var array<string, object> */
    private array $scopedInstances = [];

    /** @var list<Closure> */
    private array $disposeCallbacks = [];

    public bool $isCancelled {
        get => $this->cancellation->isCancelled;
    }

    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly CancellationToken $cancellation,
        private readonly Trace $trace,
        /** @var list<TaskMiddleware> */
        private readonly array $taskInterceptors = [],
        /** @var array<string, mixed> */
        private array $attributes = [],
        private readonly ?WorkerDispatch $workerDispatch = null,
    ) {
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object
    {
        $this->throwIfCancelled();

        /** @var class-string<T> $resolved */
        $resolved = $this->graph->aliases[$type] ?? $type;

        if ($this->graph->hasConfig($resolved)) {
            /** @var T */
            return $this->graph->config($resolved);
        }

        $compiled = $this->graph->resolve($resolved);

        if ($compiled->singleton) {
            /** @var T */
            return $this->singletons->get($type, fn(string $t): object => $this->service($t)); // @phpstan-ignore argument.type, argument.templateType
        }

        if (isset($this->scopedInstances[$resolved])) {
            /** @var T */
            return $this->scopedInstances[$resolved];
        }

        $instance = $this->createScopedInstance($compiled);

        $this->scopedInstances[$resolved] = $instance;
        $this->creationOrder[] = $resolved;

        /** @var T */
        return $instance;
    }

    public function execute(Scopeable|Executable $task): mixed
    {
        $this->throwIfCancelled();

        FiberScopeRegistry::register($this);

        try {
            return $this->executeWithBehavior($task);
        } finally {
            FiberScopeRegistry::unregister();
        }
    }

    public function executeFresh(Scopeable|Executable $task): mixed
    {
        $this->throwIfCancelled();

        $childScope = new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            $this->cancellation,
            $this->trace,
            $this->taskInterceptors,
            $this->attributes,
            $this->workerDispatch,
        );

        try {
            return $childScope->execute($task);
        } finally {
            $childScope->dispose();
        }
    }

    /**
     * @param array<string|int, Scopeable|Executable> $tasks
     * @return array<string|int, mixed>
     */
    public function concurrent(array $tasks): array
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $execute = $this->execute(...);

        return $this->traced("concurrent($count)", static function () use ($tasks, $execute): array {
            $promises = [];

            foreach ($tasks as $key => $task) {
                $promises[$key] = async(static fn(): mixed => $execute($task))();
            }

            return await(all($promises));
        });
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function race(array $tasks): mixed
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $execute = $this->execute(...);

        return $this->traced("race($count)", static function () use ($tasks, $execute): mixed {
            $promises = [];

            foreach ($tasks as $task) {
                $promises[] = async(static fn(): mixed => $execute($task))();
            }

            return await(race($promises));
        });
    }

    /** @param array<string|int, Scopeable|Executable> $tasks */
    public function any(array $tasks): mixed
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $execute = $this->execute(...);

        return $this->traced("any($count)", static function () use ($tasks, $execute): mixed {
            $promises = [];

            foreach ($tasks as $task) {
                $promises[] = async(static fn(): mixed => $execute($task))();
            }

            return await(any($promises));
        });
    }

    /**
     * @param array<string|int, mixed> $items
     * @return array<string|int, mixed>
     */
    public function map(array $items, Closure $fn, int $limit = 10): array
    {
        $this->throwIfCancelled();

        $count = count($items);
        $start = hrtime(true);
        $this->trace->log(TraceType::ConcurrentStart, "map($count, limit=$limit)");

        $index = 0;
        $results = [];
        $pending = [];
        $keys = array_keys($items);
        $execute = $this->execute(...);
        $cancellation = $this->cancellation;

        $startNext = static function () use (
            &$pending,
            &$results,
            &$index,
            $keys,
            $items,
            $fn,
            $limit,
            $execute,
            $cancellation,
        ): void {
            while (count($pending) < $limit && $index < count($keys)) {
                $cancellation->throwIfCancelled();
                $key = $keys[$index];
                $item = $items[$key];
                $currentKey = $key;
                $index++;

                $deferred = new Deferred();
                $pending[$currentKey] = $deferred->promise();

                async(static function () use (
                    $fn,
                    $item,
                    $currentKey,
                    &$results,
                    &$pending,
                    $deferred,
                    $execute,
                ): void {
                    try {
                        $task = $fn($item);
                        $results[$currentKey] = $execute($task);
                        $deferred->resolve(null);
                    } catch (\Throwable $e) {
                        $deferred->reject($e);
                    } finally {
                        unset($pending[$currentKey]);
                    }
                })();
            }
        };

        $startNext();

        while ($pending !== [] || $index < count($keys)) {
            $this->throwIfCancelled();

            if ($pending !== []) {
                await(race($pending));
            }

            $startNext();
        }

        $elapsed = (hrtime(true) - $start) / 1e6;
        $this->trace->log(TraceType::ConcurrentEnd, "map($count) completed", ['elapsed' => $elapsed]);

        $ordered = [];
        foreach ($keys as $key) {
            $ordered[$key] = $results[$key];
        }

        return $ordered;
    }

    /**
     * @param list<Scopeable|Executable> $tasks
     * @return list<mixed>
     */
    public function series(array $tasks): array
    {
        $this->throwIfCancelled();

        $results = [];
        foreach ($tasks as $task) {
            $results[] = $this->execute($task);
        }

        return $results;
    }

    public function waterfall(array $tasks): mixed
    {
        $this->throwIfCancelled();

        $result = null;
        foreach ($tasks as $task) {
            $scope = $this->withAttribute('_waterfall_previous', $result);
            $result = $scope->execute($task);
        }

        return $result;
    }

    public function delay(float $seconds): void
    {
        $this->throwIfCancelled();
        delay($seconds);
    }

    public function retry(Scopeable|Executable $task, RetryPolicy $policy): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $policy->attempts) {
            $this->throwIfCancelled();

            $attempt++;

            try {
                return $this->execute($task);
            } catch (\Throwable $e) {
                $lastException = $e;

                if (!$policy->shouldRetry($e) || $attempt >= $policy->attempts) {
                    throw $e;
                }

                $delayMs = $policy->calculateDelay($attempt);
                $this->trace->log(TraceType::Retry, "attempt $attempt", ['delay' => $delayMs]);

                $this->delay($delayMs / 1000);
            }
        }

        throw $lastException ?? new \RuntimeException("Retry exhausted with no exception");
    }

    public function settle(array $tasks): SettlementBag
    {
        $this->throwIfCancelled();

        $count = count($tasks);
        $start = hrtime(true);
        $this->trace->log(TraceType::ConcurrentStart, "settle($count)");

        $execute = $this->execute(...);
        $settlements = [];
        $promises = [];

        foreach ($tasks as $key => $task) {
            $currentKey = $key;
            $promises[$key] = async(static function () use ($task, $currentKey, &$settlements, $execute): void {
                try {
                    $result = $execute($task);
                    $settlements[$currentKey] = Settlement::ok($result);
                } catch (\Throwable $e) {
                    $settlements[$currentKey] = Settlement::err($e);
                }
            })();
        }

        await(all($promises));

        $elapsed = (hrtime(true) - $start) / 1e6;
        $this->trace->log(TraceType::ConcurrentEnd, "settle($count) completed", ['elapsed' => $elapsed]);

        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            $ordered[$key] = $settlements[$key];
        }

        return new SettlementBag($ordered);
    }

    public function timeout(float $seconds, Scopeable|Executable $task): mixed
    {
        $this->throwIfCancelled();

        $timeoutToken = CancellationToken::timeout($seconds);
        $token = CancellationToken::composite($this->cancellation, $timeoutToken);

        $childScope = new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            $token,
            $this->trace,
            $this->taskInterceptors,
            $this->attributes,
            $this->workerDispatch,
        );

        $taskPromise = async(static fn() => $childScope->execute($task))();

        $timeoutPromise = new \React\Promise\Promise(static function ($resolve, $reject) use ($timeoutToken): void {
            $timeoutToken->onCancel(static function () use ($reject): void {
                $reject(new \Phalanx\Exception\CancelledException('Timeout exceeded'));
            });
        });

        try {
            return await(race([$taskPromise, $timeoutPromise]));
        } finally {
            $childScope->dispose();
        }
    }

    public function throwIfCancelled(): void
    {
        $this->cancellation->throwIfCancelled();
    }

    public function cancellation(): CancellationToken
    {
        return $this->cancellation;
    }

    public function onDispose(Closure $callback): void
    {
        $this->disposeCallbacks[] = $callback;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }

        $this->disposed = true;

        foreach (array_reverse($this->disposeCallbacks) as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                ErrorHandler::report("Dispose callback failed: " . $e->getMessage());
            }
        }

        foreach (array_reverse($this->creationOrder) as $type) {
            $instance = $this->scopedInstances[$type] ?? null;

            if ($instance === null) {
                continue;
            }

            if (LazyFactory::isUninitialized($instance)) {
                continue;
            }

            $compiled = $this->graph->resolve($type);

            foreach ($compiled->lifecycle->onDispose as $hook) {
                try {
                    $hook($instance);
                } catch (\Throwable $e) {
                    ErrorHandler::report("Dispose hook failed for $type: " . $e->getMessage());
                }
            }

            $this->trace->log(TraceType::ServiceDispose, $compiled->shortName());
        }

        $this->creationOrder = [];
        $this->scopedInstances = [];
        $this->disposeCallbacks = [];
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    public function defer(Scopeable|Executable $task): void
    {
        $this->throwIfCancelled();

        $execute = $this->execute(...);

        async(static function () use ($task, $execute): void {
            try {
                $execute($task);
            } catch (\Throwable $e) {
                ErrorHandler::report("Deferred task failed: " . $e->getMessage());
            }
        })();
    }

    public function withAttribute(string $key, mixed $value): ExecutionScope
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            $this->cancellation,
            $this->trace,
            $this->taskInterceptors,
            $attributes,
            $this->workerDispatch,
        );
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function inWorker(Scopeable|Executable $task): mixed
    {
        return ($this->workerDispatch ?? throw new \RuntimeException(
            'Worker execution requires phalanx/parallel. Install it via: composer require phalanx/parallel'
        ))->inWorker($task, $this);
    }

    private function executeWithBehavior(Scopeable|Executable $task): mixed
    {
        $config = $this->resolveTaskConfig($task);

        $work = fn(): mixed => $this->executeCore($task);

        if ($config->timeout !== null) {
            $timeout = $config->timeout;
            $inner = $work;
            $work = fn(): mixed => $this->executeTimeout($timeout, $inner);
        }

        if ($config->retry !== null) {
            $policy = $config->retry;
            $inner = $work;
            $work = fn(): mixed => $this->executeRetry($inner, $policy);
        }

        if ($config->trace && $config->name !== '') {
            $name = $config->name;
            $inner = $work;
            $work = fn(): mixed => $this->traced($name, $inner);
        }

        return $work();
    }

    private function executeCore(Scopeable|Executable $task): mixed
    {
        $name = $this->taskName($task);
        $start = hrtime(true);

        $this->trace->log(TraceType::Executing, $name, task: $task);

        $pipeline = fn() => $task($this);

        foreach (array_reverse($this->taskInterceptors) as $mw) {
            $next = $pipeline;
            $pipeline = fn() => $mw->process($task, $this, $next);
        }

        try {
            $result = $pipeline();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Done, $name, ['elapsed' => $elapsed]);
            return $result;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Failed, $name, ['elapsed' => $elapsed, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function executeTimeout(float $seconds, callable $work): mixed
    {
        $timeoutToken = CancellationToken::timeout($seconds);
        $token = CancellationToken::composite($this->cancellation, $timeoutToken);

        $childScope = new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            $token,
            $this->trace,
            $this->taskInterceptors,
            $this->attributes,
            $this->workerDispatch,
        );

        $taskPromise = async(static fn() => $work())();

        $timeoutPromise = new \React\Promise\Promise(static function ($_, $reject) use ($timeoutToken): void {
            $timeoutToken->onCancel(static function () use ($reject): void {
                $reject(new \Phalanx\Exception\CancelledException('Timeout exceeded'));
            });
        });

        try {
            return await(race([$taskPromise, $timeoutPromise]));
        } finally {
            $childScope->dispose();
        }
    }

    private function executeRetry(callable $work, RetryPolicy $policy): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $policy->attempts) {
            $this->throwIfCancelled();
            $attempt++;

            try {
                return $work();
            } catch (\Throwable $e) {
                $lastException = $e;

                if (!$policy->shouldRetry($e) || $attempt >= $policy->attempts) {
                    throw $e;
                }

                $delayMs = $policy->calculateDelay($attempt);
                $this->trace->log(TraceType::Retry, "attempt $attempt", ['delay' => $delayMs]);

                $this->delay($delayMs / 1000);
            }
        }

        throw $lastException ?? new \RuntimeException("Retry exhausted");
    }

    private function resolveTaskConfig(Scopeable|Executable $task): TaskConfig
    {
        if (property_exists($task, 'config') && $task->config instanceof TaskConfig) {
            return $task->config;
        }

        $config = new TaskConfig();

        if ($task instanceof Retryable) {
            $config = $config->with(retry: $task->retryPolicy);
        }

        if ($task instanceof HasTimeout) {
            $config = $config->with(timeout: $task->timeout);
        }

        if ($task instanceof Traceable) {
            $config = $config->with(name: $task->traceName);
        }

        if ($task instanceof UsesPool) {
            $config = $config->with(pool: $task->pool);
        }

        if ($task instanceof HasPriority) {
            $config = $config->with(priority: $task->priority);
        }

        return $config;
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function traced(string $label, callable $operation): mixed
    {
        $start = hrtime(true);
        $this->trace->log(TraceType::ConcurrentStart, $label);

        try {
            $result = $operation();
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::ConcurrentEnd, "$label completed", ['elapsed' => $elapsed]);
            return $result;
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1e6;
            $this->trace->log(TraceType::Failed, $label, ['elapsed' => $elapsed, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function createScopedInstance(CompiledService $compiled): object
    {
        $deps = [new DeferredScope()];

        foreach ($compiled->dependencyOrder as $depType) {
            $deps[] = $this->service($depType); // @phpstan-ignore argument.type, argument.templateType
        }

        if ($compiled->lazy) {
            return LazyFactory::wrap($compiled->type, fn() => ($compiled->factory)(...$deps), $this->trace);
        }

        $this->trace->log(TraceType::ServiceInit, $compiled->shortName());

        $instance = ($compiled->factory)(...$deps);

        foreach ($compiled->lifecycle->onInit as $hook) {
            $hook($instance);
        }

        return $instance;
    }

    private function taskName(Scopeable|Executable $task): string
    {
        if ($task instanceof Traceable) {
            return $task->traceName;
        }

        return ClassNames::short($task::class);
    }
}
