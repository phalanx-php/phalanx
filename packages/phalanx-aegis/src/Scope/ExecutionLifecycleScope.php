<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use OpenSwoole\Core\Coroutine\WaitGroup;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Timer;
use Phalanx\Cancellation\AggregateException;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Concurrency\Co;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\Settlement;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Concurrency\SingleflightGroup;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Service\CompiledServiceConfig;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceGraph;
use Phalanx\Service\ServiceLifetime;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\TaskRun;
use Phalanx\Supervisor\TransactionLease;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Phalanx\Task\Traceable;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use Phalanx\Worker\WorkerDispatch;
use Phalanx\Worker\WorkerTask;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Throwable;

/**
 * Concrete ExecutionScope on Swoole.
 *
 * Phase 0+1 surface live: full TaskExecutor (concurrent, race, any, map,
 * series, waterfall, settle, timeout, retry, delay, defer, singleflight)
 * plus Scope/Suspendable/Cancellable/Disposable/ExecutionScope basics.
 * inWorker() throws until Phase 4.
 *
 * Cancellation translation (per Phase 0 substrate finding): OpenSwoole's
 * Coroutine::cancel does not raise an exception; it interrupts I/O and sets
 * isCanceled() to true. Co::sleep and concurrency primitive child wrappers
 * check this and translate to Cancelled.
 */
class ExecutionLifecycleScope implements ExecutionScope, ScopeIdentity
{
    public bool $isCancelled {
        get => $this->cancellation->isCancelled;
    }

    public string $scopeId {
        get => $this->scopeIdValue;
    }

    public RuntimeContext $runtime {
        get => $this->service(RuntimeContext::class);
    }

    /**
     * Currently active TaskRun in this scope, set while a supervised body is
     * executing. Recursive execute() reads this to set parentId on the new
     * run. Spawned children (concurrent/race/etc.) read this on their copy
     * of the parent scope to determine what they descend from.
     */
    public ?TaskRun $currentRun = null;

    /** @var array<class-string, object> */
    private array $scopedInstances = [];

    /** @var list<class-string> */
    private array $scopedCreationOrder = [];

    /** @var list<Closure(): void> */
    private array $disposeStack = [];

    /** @var list<int> */
    private array $deferredCids = [];

    /**
     * Active go()-spawned tasks awaiting completion. Keyed by spawn sequence
     * for O(1) removal in the finally block. Force-cancelled with
     * PHX-SPAWN-002 if any remain at dispose time.
     *
     * @var array<int, array{int, TaskRun}>
     */
    private array $goSpawns = [];

    private int $goSpawnSeq = 0;

    private bool $disposed = false;

    private readonly string $scopeIdValue;

    private readonly SingleflightGroup $singleflightGroup;

    /** @var array<string, mixed> */
    private array $resources;

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $resources
     * @param list<ServiceTransformationMiddleware> $serviceMiddlewares
     * @param list<TaskMiddleware> $taskMiddlewares
     */
    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly CancellationToken $cancellation,
        private readonly Trace $traceLog,
        private readonly Supervisor $supervisor,
        private array $attributes = [],
        ?SingleflightGroup $singleflight = null,
        private readonly array $serviceMiddlewares = [],
        private readonly array $taskMiddlewares = [],
        private readonly ?WorkerDispatch $workerDispatch = null,
        private readonly ?string $parentScopeId = null,
        array &$resources = [],
    ) {
        $this->resources = &$resources;
        $this->singleflightGroup = $singleflight ?? new SingleflightGroup();
        $this->scopeIdValue = $this->supervisor->nextScopeId();
        $this->supervisor->registerScope(
            $this->scopeIdValue,
            $this->parentScopeId,
            self::class,
            Coroutine::getCid(),
        );
    }

    public function supervisor(): Supervisor
    {
        return $this->supervisor;
    }

    public function currentTaskRun(): ?TaskRun
    {
        return $this->currentRun;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    public function service(string $type): object
    {
        $this->throwIfCancelled();
        $resolved = $this->graph->alias($type);

        if ($this->graph->hasContextConfig($type)) {
            /** @var T $config */
            $config = $this->graph->contextConfig($type);

            return $config;
        }

        $config = $this->graph->resolve($type);

        $scope = $this;
        $build = static fn(): object => self::build($config, $scope);

        if ($config->lifetime === ServiceLifetime::Singleton) {
            /** @var T $instance */
            $instance = $this->singletons->get(
                $resolved,
                static fn(): object => self::runMiddleware($resolved, $build, $scope),
            );

            return $instance;
        }

        if (isset($this->scopedInstances[$resolved])) {
            /** @var T $instance */
            $instance = $this->scopedInstances[$resolved];

            return $instance;
        }

        if ($config->lazy) {
            /** @var T $instance */
            $instance = $config->reflection()->newLazyProxy(static function () use ($scope, $resolved, $build): object {
                return self::runMiddleware($resolved, $build, $scope);
            });

            $this->scopedInstances[$resolved] = $instance;
            $this->scopedCreationOrder[] = $resolved;

            return $instance;
        }

        $instance = self::runMiddleware($resolved, $build, $this);
        $this->scopedInstances[$resolved] = $instance;
        $this->scopedCreationOrder[] = $resolved;

        /** @var T $instance */
        return $instance;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function resource(string $key, mixed $default = null): mixed
    {
        return $this->resources[$key] ?? $default;
    }

    public function setResource(string $key, mixed $value): void
    {
        $this->resources[$key] = $value;
    }

    public function withAttribute(string $key, mixed $value): ExecutionScope
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;
        $child = new self(
            $this->graph,
            $this->singletons,
            $this->cancellation,
            $this->traceLog,
            $this->supervisor,
            $attributes,
            $this->singleflightGroup,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
            parentScopeId: $this->scopeIdValue,
            resources: $this->resources,
        );
        $this->onDispose(static function () use ($child): void {
            $child->dispose();
        });

        return $child;
    }

    public function trace(): Trace
    {
        return $this->traceLog;
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
        if ($this->disposed) {
            try {
                $callback();
            } catch (Throwable) {
            }
            return;
        }
        $this->disposeStack[] = $callback;
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        foreach ($this->deferredCids as $cid) {
            if (Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
        }
        $this->deferredCids = [];

        $callbacks = array_reverse($this->disposeStack);
        $this->disposeStack = [];
        foreach ($callbacks as $cb) {
            try {
                $cb();
            } catch (Throwable) {
            }
        }

        foreach (array_reverse($this->scopedCreationOrder) as $type) {
            $instance = $this->scopedInstances[$type] ?? null;
            if ($instance === null) {
                continue;
            }
            $config = $this->graph->configs[$type] ?? null;
            if ($config === null) {
                continue;
            }
            foreach ($config->onDisposeHooks as $hook) {
                try {
                    $hook($instance);
                } catch (Throwable) {
                }
            }
        }
        $this->scopedInstances = [];
        $this->scopedCreationOrder = [];

        $this->forceCancelGoSpawns();

        $this->supervisor->disposeScope($this->scopeIdValue);
    }

    public function call(Closure $fn, ?WaitReason $waitReason = null): mixed
    {
        $this->throwIfCancelled();
        $cid = Coroutine::getCid();
        $cancelKey = $this->cancellation->onCancel(static function () use ($cid): void {
            if ($cid > 0 && Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
        });
        $clearWait = ($this->currentRun !== null && $waitReason !== null)
            ? $this->supervisor->beginWait($this->currentRun, $waitReason)
            : null;
        try {
            $result = $fn();
            if ($this->isCancelled || Coroutine::isCanceled()) {
                throw new Cancelled('cancelled during call()');
            }
            return $result;
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->isCancelled || Coroutine::isCanceled()) {
                throw new Cancelled('cancelled during call(): ' . $e->getMessage());
            }
            throw $e;
        } finally {
            $this->cancellation->offCancel($cancelKey);
            if ($clearWait !== null) {
                $clearWait();
            }
        }
    }

    /**
     * Run a task to completion, supervised end-to-end.
     *
     * Slice 2 wiring: middleware chain runs OUTSIDE Supervisor::start().
     * RetryMiddleware that calls $next() multiple times produces a
     * distinct TaskRun per attempt — visible in the ledger as siblings
     * sharing the same name. TimeoutMiddleware creates a child scope with
     * a tighter cancellation; that child's execute() opens a nested
     * TaskRun parented to the outer.
     *
     * Wraps the task body with:
     *   - CoroutineScopeRegistry install/clear so DeferredScope resolves
     *   - currentRun tracking so recursive execute() and concurrent()
     *     children link parent/child correctly
     *   - Supervisor lifecycle: register run -> markRunning -> body ->
     *     complete | fail | cancel -> reap (always in finally)
     */
    public function execute(Scopeable|Executable|Closure $task): mixed
    {
        return $this->dispatchSupervised($task, DispatchMode::Inline);
    }

    public function executeFresh(Scopeable|Executable|Closure $task): mixed
    {
        $child = new self(
            $this->graph,
            $this->singletons,
            $this->cancellation,
            $this->traceLog,
            $this->supervisor,
            $this->attributes,
            $this->singleflightGroup,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
            parentScopeId: $this->scopeIdValue,
            resources: $this->resources,
        );
        try {
            return $child->execute($task);
        } finally {
            $child->dispose();
        }
    }

    /** @return array<string|int, mixed> */
    public function concurrent(Scopeable|Executable|Closure ...$tasks): array
    {
        $this->throwIfCancelled();
        if ($tasks === []) {
            return [];
        }

        $wg = new WaitGroup();
        $wg->add(count($tasks));
        $results = [];
        $errors = [];
        $cids = [];
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids): void {
            self::cancelCoroutines($cids);
        });

        try {
            foreach ($tasks as $key => $task) {
                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;

                $cid = Coroutine::create(static function () use (
                    $childScope,
                    $task,
                    $key,
                    $wg,
                    &$results,
                    &$errors,
                ): void {
                    CoroutineScopeRegistry::install($childScope);
                    try {
                        $results[$key] = $childScope->dispatchSupervised($task, DispatchMode::Concurrent);
                        if (Coroutine::isCanceled()) {
                            unset($results[$key]);
                            $errors[$key] = new Cancelled("task {$key} cancelled");
                        }
                    } catch (Cancelled $e) {
                        $errors[$key] = $e;
                    } catch (Throwable $e) {
                        $errors[$key] = Coroutine::isCanceled()
                            ? new Cancelled("task {$key} cancelled: {$e->getMessage()}")
                            : $e;
                    } finally {
                        CoroutineScopeRegistry::clear();
                        $wg->done();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                }
            }

            $wg->wait();

            if ($errors !== []) {
                foreach ($cids as $cid) {
                    if (Coroutine::exists($cid)) {
                        Coroutine::cancel($cid);
                    }
                }
                throw reset($errors);
            }
        } finally {
            $this->cancellation->offCancel($cancelKey);
            // Child scopes own isolated scoped services, dispose stacks, and
            // cancellation listeners, so each one is disposed independently.
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }

        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }

    public function race(Scopeable|Executable|Closure ...$tasks): mixed
    {
        $this->throwIfCancelled();
        if ($tasks === []) {
            throw new RuntimeException('race(): empty task list');
        }

        $count = count($tasks);
        $channel = new Channel($count);
        $cids = [];
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids): void {
            self::cancelCoroutines($cids);
        });

        try {
            foreach ($tasks as $key => $task) {
                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;

                $cid = Coroutine::create(static function () use ($childScope, $task, $key, $channel): void {
                    CoroutineScopeRegistry::install($childScope);
                    try {
                        $value = $childScope->dispatchSupervised($task, DispatchMode::Concurrent);
                        if (Coroutine::isCanceled()) {
                            $channel->push(['err', $key, new Cancelled("task {$key} cancelled")], 0.001);
                            return;
                        }
                        $channel->push(['ok', $key, $value], 0.001);
                    } catch (Throwable $e) {
                        $channel->push(['err', $key, $e], 0.001);
                    } finally {
                        CoroutineScopeRegistry::clear();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                }
            }

            if ($cids === []) {
                throw new RuntimeException('race(): all coroutine spawns failed (resource exhaustion)');
            }

            $first = $channel->pop();

            // Cancel losers via their own child cancellation tokens so each
            // loser sees a clean Cancelled and runs its dispose stack.
            foreach ($childScopes as $child) {
                $child->cancellation->cancel();
            }
            foreach ($cids as $cid) {
                if (Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            }

            [$kind, , $value] = $first;
            if ($kind === 'err') {
                throw $value;
            }
            return $value;
        } finally {
            $this->cancellation->offCancel($cancelKey);
            $channel->close();
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }
    }

    public function any(Scopeable|Executable|Closure ...$tasks): mixed
    {
        $this->throwIfCancelled();
        if ($tasks === []) {
            throw new RuntimeException('any(): empty task list');
        }

        $count = count($tasks);
        $channel = new Channel($count);
        $cids = [];
        $errors = [];
        $remaining = $count;
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids): void {
            self::cancelCoroutines($cids);
        });

        try {
            foreach ($tasks as $key => $task) {
                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;

                $cid = Coroutine::create(static function () use ($childScope, $task, $key, $channel): void {
                    CoroutineScopeRegistry::install($childScope);
                    try {
                        $value = $childScope->dispatchSupervised($task, DispatchMode::Concurrent);
                        if (Coroutine::isCanceled()) {
                            $channel->push(['err', $key, new Cancelled("task {$key} cancelled")], 0.001);
                            return;
                        }
                        $channel->push(['ok', $key, $value], 0.001);
                    } catch (Throwable $e) {
                        $channel->push(['err', $key, $e], 0.001);
                    } finally {
                        CoroutineScopeRegistry::clear();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                }
            }

            if ($cids === []) {
                throw new RuntimeException('any(): all coroutine spawns failed (resource exhaustion)');
            }

            while ($remaining-- > 0) {
                [$kind, $key, $value] = $channel->pop();
                if ($kind === 'ok') {
                    foreach ($childScopes as $child) {
                        $child->cancellation->cancel();
                    }
                    foreach ($cids as $cid) {
                        if (Coroutine::exists($cid)) {
                            Coroutine::cancel($cid);
                        }
                    }
                    return $value;
                }
                $errors[$key] = $value;
            }

            throw new AggregateException($errors);
        } finally {
            $this->cancellation->offCancel($cancelKey);
            $channel->close();
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }
    }

    public function map(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        $this->throwIfCancelled();
        $itemsArr = is_array($items) ? $items : iterator_to_array($items);
        if ($itemsArr === []) {
            return [];
        }

        $effectiveLimit = max(1, min($limit, count($itemsArr)));
        $sem = new Channel($effectiveLimit);
        $wg = new WaitGroup();
        $wg->add(count($itemsArr));
        $results = [];
        $errors = [];
        $cids = [];
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids): void {
            self::cancelCoroutines($cids);
        });

        try {
            foreach ($itemsArr as $key => $item) {
                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;

                $cid = Coroutine::create(static function () use (
                    $childScope,
                    $fn,
                    $onEach,
                    $item,
                    $key,
                    $sem,
                    $wg,
                    &$results,
                    &$errors,
                ): void {
                    CoroutineScopeRegistry::install($childScope);
                    $sem->push(1);
                    try {
                        $task = $fn($item);
                        $value = $childScope->dispatchSupervised($task, DispatchMode::Concurrent);
                        if (Coroutine::isCanceled()) {
                            $errors[$key] = new Cancelled("map[{$key}] cancelled");
                        } else {
                            $results[$key] = $value;
                            if ($onEach !== null) {
                                $onEach($key, $value);
                            }
                        }
                    } catch (Cancelled $e) {
                        $errors[$key] = $e;
                    } catch (Throwable $e) {
                        $errors[$key] = Coroutine::isCanceled()
                            ? new Cancelled("map[{$key}] cancelled: {$e->getMessage()}")
                            : $e;
                    } finally {
                        $sem->pop();
                        CoroutineScopeRegistry::clear();
                        $wg->done();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                }
            }

            $wg->wait();

            if ($errors !== []) {
                throw reset($errors);
            }
        } finally {
            $this->cancellation->offCancel($cancelKey);
            $sem->close();
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }

        $ordered = [];
        foreach (array_keys($itemsArr) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }

    /** @return array<string|int, mixed> */
    public function series(Scopeable|Executable|Closure ...$tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $this->throwIfCancelled();
            $results[$key] = $this->dispatchSupervised($task, DispatchMode::Inline);
        }
        return $results;
    }

    public function waterfall(Scopeable|Executable|Closure ...$tasks): mixed
    {
        $previous = null;
        $first = true;
        $stepScope = null;
        foreach ($tasks as $task) {
            $this->throwIfCancelled();
            $previousStepScope = $stepScope;
            $stepScope = $first
                ? $this
                : $this->withAttribute('_waterfall_previous', $previous);
            $first = false;
            $previous = $stepScope->executeFresh($task);
            if ($previousStepScope !== null && $previousStepScope !== $this) {
                $previousStepScope->dispose();
            }
        }
        if ($stepScope !== null && $stepScope !== $this) {
            $stepScope->dispose();
        }
        return $previous;
    }

    public function settle(Scopeable|Executable|Closure ...$tasks): SettlementBag
    {
        $this->throwIfCancelled();
        if ($tasks === []) {
            return new SettlementBag([]);
        }

        $wg = new WaitGroup();
        $wg->add(count($tasks));
        $bag = [];
        $cids = [];
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids): void {
            self::cancelCoroutines($cids);
        });

        try {
            foreach ($tasks as $key => $task) {
                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;

                $cid = Coroutine::create(static function () use ($childScope, $task, $key, $wg, &$bag): void {
                    CoroutineScopeRegistry::install($childScope);
                    try {
                        $value = $childScope->dispatchSupervised($task, DispatchMode::Concurrent);
                        if (Coroutine::isCanceled()) {
                            $bag[$key] = Settlement::err(new Cancelled("settle[{$key}] cancelled"));
                        } else {
                            $bag[$key] = Settlement::ok($value);
                        }
                    } catch (Throwable $e) {
                        $bag[$key] = Settlement::err(
                            Coroutine::isCanceled()
                                ? new Cancelled("settle[{$key}] cancelled: {$e->getMessage()}")
                                : $e,
                        );
                    } finally {
                        CoroutineScopeRegistry::clear();
                        $wg->done();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                }
            }

            $wg->wait();
        } finally {
            $this->cancellation->offCancel($cancelKey);
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }

        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            if (array_key_exists($key, $bag)) {
                $ordered[$key] = $bag[$key];
            }
        }
        return new SettlementBag($ordered);
    }

    public function timeout(float $seconds, Scopeable|Executable|Closure $task): mixed
    {
        $this->throwIfCancelled();
        $timeoutToken = CancellationToken::timeout($seconds);
        $composite = CancellationToken::composite($this->cancellation, $timeoutToken);

        $child = new self(
            $this->graph,
            $this->singletons,
            $composite,
            $this->traceLog,
            $this->supervisor,
            $this->attributes,
            $this->singleflightGroup,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
            parentScopeId: $this->scopeIdValue,
            resources: $this->resources,
        );

        try {
            return $child->execute($task);
        } catch (Cancelled $e) {
            if ($timeoutToken->isCancelled && !$this->cancellation->isCancelled) {
                $this->traceLog->log(TraceType::Timeout, 'timeout', ['seconds' => $seconds]);
                throw new Cancelled("timeout after {$seconds}s");
            }
            throw $e;
        } finally {
            $timeoutToken->cancel();
            $child->dispose();
        }
    }

    public function retry(Scopeable|Executable|Closure $task, RetryPolicy $policy): mixed
    {
        $lastError = null;
        for ($attempt = 0; $attempt < $policy->attempts; $attempt++) {
            $this->throwIfCancelled();
            try {
                return $this->dispatchSupervised($task, DispatchMode::Inline);
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e;
                if (!$policy->shouldRetry($e)) {
                    throw $e;
                }
                if ($attempt < $policy->attempts - 1) {
                    $this->traceLog->log(
                        TraceType::Retry,
                        'retry',
                        ['attempt' => $attempt + 1, 'error' => $e->getMessage()],
                    );
                    $this->delay($policy->calculateDelay($attempt) / 1000);
                }
            }
        }
        throw $lastError ?? new RuntimeException('retry: no attempts executed');
    }

    public function delay(float $seconds): void
    {
        $this->throwIfCancelled();
        $cid = Coroutine::getCid();
        $cancelKey = $this->cancellation->onCancel(static function () use ($cid): void {
            if ($cid > 0 && Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
        });
        $clearWait = ($this->currentRun !== null)
            ? $this->supervisor->beginWait($this->currentRun, WaitReason::delay($seconds))
            : null;
        try {
            Co::sleep($seconds);
        } finally {
            $this->cancellation->offCancel($cancelKey);
            if ($clearWait !== null) {
                $clearWait();
            }
        }
    }

    public function periodic(float $interval, Closure $tick): Subscription
    {
        if ($this->disposed) {
            throw new RuntimeException('periodic(): cannot schedule on a disposed scope');
        }

        $ms = max(1, (int) round($interval * 1000));
        $self = $this;

        $timerId = Timer::tick($ms, static function () use ($self, $tick): void {
            if ($self->disposed) {
                return;
            }
            CoroutineScopeRegistry::install($self);
            try {
                $tick();
            } catch (Throwable $e) {
                $self->traceLog->log(TraceType::Defer, 'periodic.error', ['error' => $e->getMessage()]);
            } finally {
                CoroutineScopeRegistry::clear();
            }
        });

        if (!is_int($timerId)) {
            throw new RuntimeException('periodic(): OpenSwoole Timer::tick refused to register');
        }

        $subscription = new PeriodicSubscription($timerId);
        $this->onDispose(static function () use ($subscription): void {
            $subscription->cancel();
        });

        return $subscription;
    }

    public function defer(Scopeable|Executable|Closure $task): void
    {
        if ($this->disposed) {
            return;
        }
        // @dev-cleanup-ignore — child scope disposed on completion; parent onDispose owns cancellation via deferredCids
        $childScope = $this->makeChildScope($this->currentRun);
        $traceLog = $this->traceLog;
        $cid = Coroutine::create(static function () use ($childScope, $task, $traceLog): void {
            CoroutineScopeRegistry::install($childScope);
            try {
                $childScope->dispatchSupervised($task, DispatchMode::Concurrent);
            } catch (Throwable $e) {
                $traceLog->log(TraceType::Defer, 'defer.error', ['error' => $e->getMessage()]);
            } finally {
                $childScope->dispose();
                CoroutineScopeRegistry::clear();
            }
        });
        if ($cid !== false) {
            $this->deferredCids[] = $cid;
        }
    }

    public function go(Closure $fn, ?string $name = null): TaskRun
    {
        if ($this->disposed) {
            throw new RuntimeException('go(): cannot spawn on a disposed scope');
        }

        $childScope = $this->makeChildScope($this->currentRun);
        $parentRunId = $this->currentRun?->id;
        $resolvedName = $name ?? self::closureLocation($fn) ?? 'go-spawn';

        $run = $this->supervisor->start(
            task: $fn,
            parent: $childScope,
            mode: DispatchMode::Concurrent,
            name: $resolvedName,
            parentRunId: $parentRunId,
            token: $childScope->cancellation,
        );

        $supervisor = $this->supervisor;
        $traceLog = $this->traceLog;
        $goSpawns = &$this->goSpawns;
        $spawnKey = $this->goSpawnSeq++;

        $runCancelKey = -1;

        $cid = Coroutine::create(static function () use (
            $childScope,
            $fn,
            $run,
            $supervisor,
            $traceLog,
            &$runCancelKey,
            &$goSpawns,
            $spawnKey,
        ): void {
            CoroutineScopeRegistry::install($childScope);
            $childScope->currentRun = $run;
            $supervisor->markRunning($run);
            try {
                $value = $fn($childScope);
                $supervisor->complete($run, $value);
            } catch (Cancelled) {
                $supervisor->cancel($run);
            } catch (Throwable $e) {
                $supervisor->fail($run, $e);
                $traceLog->log(
                    TraceType::Defer,
                    'PHX-SPAWN-001',
                    [
                        'run' => $run->id,
                        'task' => $run->name,
                        'error' => $e::class,
                        'message' => $e->getMessage(),
                        'detail' => 'go()-spawned task threw; error caught at boundary',
                    ],
                );
            } finally {
                $run->cancellation->offCancel($runCancelKey);
                $run->cancellation->release();
                $supervisor->reap($run);
                $childScope->dispose();
                unset($goSpawns[$spawnKey]);
                CoroutineScopeRegistry::clear();
            }
        });

        if ($cid !== false) {
            $runCancelKey = $run->cancellation->onCancel(static function () use ($cid): void {
                if ($cid > 0 && Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            });
            if ($run->cancellation->isCancelled && Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
            $goSpawns[$spawnKey] = [$cid, $run];
        }

        return $run;
    }

    public function singleflight(string $key, Scopeable|Executable|Closure $task): mixed
    {
        $this->throwIfCancelled();
        $self = $this;
        return $this->singleflightGroup->do(
            $key,
            static fn(): mixed => $self->dispatchSupervised($task, DispatchMode::Inline),
            $this->cancellation,
            static function () use ($self, $key): ?Closure {
                if ($self->currentRun === null) {
                    return null;
                }

                return $self->supervisor->beginWait($self->currentRun, WaitReason::singleflight($key));
            },
        );
    }

    public function inWorker(WorkerTask $task): mixed
    {
        if ($this->workerDispatch === null) {
            throw new RuntimeException(
                'inWorker(): no WorkerDispatch configured. Use ApplicationBuilder::withWorkerDispatch().',
            );
        }

        return $this->dispatchWorkerSupervised($task);
    }

    /** @return array<string|int, mixed> */
    public function parallel(WorkerTask ...$tasks): array
    {
        $this->throwIfCancelled();
        if ($this->workerDispatch === null) {
            throw new RuntimeException(
                'parallel(): no WorkerDispatch configured. Use ApplicationBuilder::withWorkerDispatch().',
            );
        }
        if ($tasks === []) {
            return [];
        }

        $wg = new WaitGroup();
        $results = [];
        $errors = [];
        $firstError = null;
        $cids = [];
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids, &$childScopes): void {
            self::cancelWorkerBatch($childScopes, $cids);
        });

        try {
            foreach ($tasks as $key => $task) {
                if ($firstError !== null) {
                    break;
                }

                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;
                $wg->add(1);

                $cid = Coroutine::create(static function () use (
                    $childScope,
                    $task,
                    $key,
                    $wg,
                    &$results,
                    &$errors,
                    &$firstError,
                    &$childScopes,
                    &$cids,
                ): void {
                    CoroutineScopeRegistry::install($childScope);
                    try {
                        $results[$key] = $childScope->dispatchWorkerSupervised($task);
                        if (Coroutine::isCanceled()) {
                            unset($results[$key]);
                            $errors[$key] = new Cancelled("parallel[{$key}] cancelled");
                        }
                    } catch (Cancelled $e) {
                        $errors[$key] = $e;
                        $firstError = self::failWorkerBatch($e, $firstError, $childScopes, $cids);
                    } catch (Throwable $e) {
                        $errors[$key] = Coroutine::isCanceled()
                            ? new Cancelled("parallel[{$key}] cancelled: {$e->getMessage()}")
                            : $e;
                        $firstError = self::failWorkerBatch($errors[$key], $firstError, $childScopes, $cids);
                    } finally {
                        CoroutineScopeRegistry::clear();
                        $wg->done();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                } else {
                    $errors[$key] = new RuntimeException("parallel[{$key}] failed to create worker coroutine");
                    $firstError = self::failWorkerBatch($errors[$key], $firstError, $childScopes, $cids);
                    $wg->done();
                    break;
                }
            }

            $wg->wait();

            if ($firstError !== null) {
                throw $firstError;
            }
        } finally {
            $this->cancellation->offCancel($cancelKey);
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }

        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }

    public function settleParallel(WorkerTask ...$tasks): SettlementBag
    {
        $this->throwIfCancelled();
        if ($this->workerDispatch === null) {
            throw new RuntimeException(
                'settleParallel(): no WorkerDispatch configured. Use ApplicationBuilder::withWorkerDispatch().',
            );
        }
        if ($tasks === []) {
            return new SettlementBag([]);
        }

        $wg = new WaitGroup();
        $bag = [];
        $cids = [];
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids): void {
            self::cancelCoroutines($cids);
        });

        try {
            foreach ($tasks as $key => $task) {
                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;
                $wg->add(1);

                $cid = Coroutine::create(static function () use ($childScope, $task, $key, $wg, &$bag): void {
                    CoroutineScopeRegistry::install($childScope);
                    try {
                        $value = $childScope->dispatchWorkerSupervised($task);
                        if (Coroutine::isCanceled()) {
                            $bag[$key] = Settlement::err(new Cancelled("settleParallel[{$key}] cancelled"));
                        } else {
                            $bag[$key] = Settlement::ok($value);
                        }
                    } catch (Throwable $e) {
                        $bag[$key] = Settlement::err(
                            Coroutine::isCanceled()
                                ? new Cancelled("settleParallel[{$key}] cancelled: {$e->getMessage()}")
                                : $e,
                        );
                    } finally {
                        CoroutineScopeRegistry::clear();
                        $wg->done();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                } else {
                    $bag[$key] = Settlement::err(
                        new RuntimeException("settleParallel[{$key}] failed to create worker coroutine"),
                    );
                    $wg->done();
                }
            }

            $wg->wait();
        } finally {
            $this->cancellation->offCancel($cancelKey);
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }

        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            if (array_key_exists($key, $bag)) {
                $ordered[$key] = $bag[$key];
            }
        }
        return new SettlementBag($ordered);
    }

    public function mapParallel(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        $this->throwIfCancelled();
        if ($this->workerDispatch === null) {
            throw new RuntimeException(
                'mapParallel(): no WorkerDispatch configured. Use ApplicationBuilder::withWorkerDispatch().',
            );
        }

        $itemsArr = is_array($items) ? $items : iterator_to_array($items);
        if ($itemsArr === []) {
            return [];
        }

        $entries = [];
        foreach ($itemsArr as $key => $item) {
            $entries[] = [$key, $item];
        }

        $effectiveLimit = max(1, min($limit, count($entries)));
        $wg = new WaitGroup();
        $results = [];
        $errors = [];
        $firstError = null;
        $cids = [];
        $next = 0;
        /** @var list<self> $childScopes */
        $childScopes = [];
        $parentRun = $this->currentRun;

        $cancelKey = $this->cancellation->onCancel(static function () use (&$cids, &$childScopes): void {
            self::cancelWorkerBatch($childScopes, $cids);
        });

        try {
            for ($slot = 0; $slot < $effectiveLimit; $slot++) {
                if ($firstError !== null) {
                    break;
                }

                $childScope = $this->makeChildScope($parentRun);
                $childScopes[] = $childScope;
                $wg->add(1);

                $cid = Coroutine::create(static function () use (
                    $childScope,
                    $fn,
                    $onEach,
                    $wg,
                    $entries,
                    &$results,
                    &$errors,
                    &$firstError,
                    &$next,
                    &$childScopes,
                    &$cids,
                ): void {
                    CoroutineScopeRegistry::install($childScope);
                    try {
                        while ($firstError === null && !$childScope->cancellation->isCancelled) {
                            $index = $next++;
                            if (!array_key_exists($index, $entries)) {
                                break;
                            }

                            [$key, $item] = $entries[$index];
                            try {
                                $task = $fn($item);
                                if (!$task instanceof WorkerTask) {
                                    throw new RuntimeException('mapParallel() task factory must return a WorkerTask.');
                                }
                                $value = $childScope->dispatchWorkerSupervised($task);
                                if (Coroutine::isCanceled()) {
                                    $errors[$key] = new Cancelled("mapParallel[{$key}] cancelled");
                                } else {
                                    $results[$key] = $value;
                                    if ($onEach !== null) {
                                        $onEach($key, $value);
                                    }
                                }
                            } catch (Cancelled $e) {
                                $errors[$key] = $e;
                                $firstError = self::failWorkerBatch($e, $firstError, $childScopes, $cids);
                            } catch (Throwable $e) {
                                $errors[$key] = Coroutine::isCanceled()
                                    ? new Cancelled("mapParallel[{$key}] cancelled: {$e->getMessage()}")
                                    : $e;
                                $firstError = self::failWorkerBatch($errors[$key], $firstError, $childScopes, $cids);
                            }
                        }
                    } finally {
                        CoroutineScopeRegistry::clear();
                        $wg->done();
                    }
                });
                if ($cid !== false) {
                    $cids[] = $cid;
                } else {
                    $errors[$slot] = new RuntimeException("mapParallel worker {$slot} failed to create coroutine");
                    $firstError = self::failWorkerBatch($errors[$slot], $firstError, $childScopes, $cids);
                    $wg->done();
                    break;
                }
            }

            $wg->wait();

            if ($firstError !== null) {
                throw $firstError;
            }
        } finally {
            $this->cancellation->offCancel($cancelKey);
            foreach ($childScopes as $child) {
                $child->dispose();
            }
        }

        $ordered = [];
        foreach (array_keys($itemsArr) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }

    public function transaction(TransactionLease $lease, Closure $body): mixed
    {
        $this->throwIfCancelled();

        if ($this->currentRun === null) {
            return $this->execute(Task::of(
                static fn(ExecutionScope $scope): mixed => $scope->transaction($lease, $body),
            ));
        }

        $txScope = new TransactionLifecycleScope($this, $lease);
        return $this->supervisor->withLease(
            $this->currentRun,
            $lease,
            static fn(): mixed => $body($txScope),
        );
    }

    /**
     * Internal: run a task through the full pipeline (middleware chain ->
     * supervisor lifecycle -> body) with an explicit DispatchMode.
     *
     * Public execute() is the user-visible entry — always Inline. The
     * concurrent/race/any/map/settle/defer primitives spawn child scopes
     * and call this on each child with DispatchMode::Concurrent so the
     * ledger / diagnostics surface accurately reflects how each child
     * was dispatched.
     *
     * Visibility: `protected` so other instances of the same class
     * (sibling/child scopes) can call it; not part of the public scope API.
     */
    protected function dispatchSupervised(
        Scopeable|Executable|Closure $task,
        DispatchMode $mode,
    ): mixed {
        $this->throwIfCancelled();
        $self = $this;
        return self::runTaskMiddleware(
            $task,
            static fn(ExecutionScope $scope): mixed => $self->runSupervised(
                $task,
                $scope instanceof self ? $scope : $self,
                $mode,
            ),
            $this,
        );
    }

    protected function dispatchWorkerSupervised(WorkerTask $task): mixed
    {
        $this->throwIfCancelled();
        $self = $this;
        return self::runTaskMiddleware(
            $task,
            static fn(ExecutionScope $scope): mixed => $self->runWorkerSupervised(
                $task,
                $scope instanceof self ? $scope : $self,
            ),
            $this,
        );
    }

    /**
     * Identity for diagnostics (priority order):
     *   1. Traceable->traceName when set and non-empty
     *   2. Task::of(...) source location ("file.php:line")
     *   3. Closure source location via reflection
     *   4. Class FQCN for invokables
     *   5. Anonymous-class fallback to the supervisor-generated id
     */
    private static function resolveTaskName(Scopeable|Executable|Closure $task): string
    {
        if ($task instanceof Traceable) {
            $hint = $task->traceName;
            if ($hint !== '') {
                return $hint;
            }
        }

        if ($task instanceof Task) {
            return $task->sourceLocation;
        }

        if ($task instanceof Closure) {
            $location = self::closureLocation($task);
            return $location ?? Closure::class;
        }

        $class = $task::class;
        if (str_contains($class, '@anonymous')) {
            return preg_replace('/@anonymous.*$/', '@anonymous', $class) ?? 'anonymous';
        }
        return $class;
    }

    private static function closureLocation(Closure $fn): ?string
    {
        try {
            $reflection = new ReflectionFunction($fn);
            $file = $reflection->getFileName();
            if ($file === false) {
                return null;
            }
            return basename($file) . ':' . $reflection->getStartLine();
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<int> $cids */
    private static function cancelCoroutines(array $cids): void
    {
        $current = Coroutine::getCid();
        foreach ($cids as $cid) {
            if ($cid === $current) {
                continue;
            }

            if (Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
        }
    }

    /**
     * @param list<self> $childScopes
     * @param list<int> $cids
     */
    private static function cancelWorkerBatch(array $childScopes, array $cids): void
    {
        foreach ($childScopes as $child) {
            $child->cancellation->cancel();
        }
        self::cancelCoroutines($cids);
    }

    /**
     * @param list<self> $childScopes
     * @param list<int> $cids
     */
    private static function failWorkerBatch(
        Throwable $error,
        ?Throwable $firstError,
        array $childScopes,
        array $cids,
    ): Throwable {
        if ($firstError !== null) {
            return $firstError;
        }

        self::cancelWorkerBatch($childScopes, $cids);
        return $error;
    }

    private static function invokeTask(Scopeable|Executable|Closure $task, self $scope): mixed
    {
        return $task($scope);
    }

    /**
     * Wrap the build closure in the middleware chain. Middlewares are invoked
     * in registration order (first-registered runs outermost). Each calls
     * $next() to descend; the innermost call invokes $build directly.
     *
     * @param Closure(): object $build
     */
    private static function runMiddleware(string $type, Closure $build, self $scope): object
    {
        if ($scope->serviceMiddlewares === []) {
            return $build();
        }

        $next = $build;
        foreach (array_reverse($scope->serviceMiddlewares) as $middleware) {
            $current = $next;
            $next = static fn(): object => $middleware->transform($type, $current, $scope);
        }
        return $next();
    }

    /**
     * Wrap task execution in the registered TaskMiddleware chain. First-registered
     * runs outermost. Each middleware decides whether to honor a behavioral
     * interface on $task (Retryable, HasTimeout, Traceable, ...) and wrap the
     * inner closure or just delegate.
     *
     * @param Closure(ExecutionScope): mixed $invoke
     */
    private static function runTaskMiddleware(Scopeable|Executable|Closure $task, Closure $invoke, self $scope): mixed
    {
        if ($scope->taskMiddlewares === []) {
            return $invoke($scope);
        }

        $next = $invoke;
        foreach (array_reverse($scope->taskMiddlewares) as $middleware) {
            $current = $next;
            $next = static fn(ExecutionScope $s): mixed => $middleware->handle($task, $s, $current);
        }
        return $next($scope);
    }

    private static function build(CompiledServiceConfig $config, self $scope): object
    {
        if ($config->factoryFn === null) {
            throw new RuntimeException("Service {$config->type} has no factory");
        }
        $deps = self::resolveFactoryDependencies($config, $scope);
        $scope->traceLog->log(TraceType::ServiceResolve, $config->type);
        $instance = ($config->factoryFn)(...$deps);
        foreach ($config->onInitHooks as $hook) {
            $hook($instance);
        }
        return $instance;
    }

    /** @return list<mixed> */
    private static function resolveFactoryDependencies(CompiledServiceConfig $config, self $scope): array
    {
        $factory = $config->factoryFn;
        if ($factory === null) {
            throw new RuntimeException("Service {$config->type} has no factory");
        }

        if ($config->needsTypes !== []) {
            $deps = [];
            foreach ($config->needsTypes as $needed) {
                $deps[] = $scope->service($needed);
            }

            return $deps;
        }

        $deps = [];
        foreach ((new ReflectionFunction($factory))->getParameters() as $parameter) {
            $deps[] = self::resolveFactoryParameter($config, $parameter, $scope);
        }

        return $deps;
    }

    private static function resolveFactoryParameter(CompiledServiceConfig $config, ReflectionParameter $parameter, self $scope): mixed
    {
        $type = $parameter->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            throw new RuntimeException(sprintf(
                'Service %s factory parameter $%s must declare a single object type.',
                $config->type,
                $parameter->getName(),
            ));
        }

        $typeName = $type->getName();
        if ($scope instanceof $typeName) {
            return $scope;
        }

        /** @var class-string $serviceType */
        $serviceType = $typeName;
        return $scope->service($serviceType);
    }

    private function forceCancelGoSpawns(): void
    {
        foreach ($this->goSpawns as [$cid, $run]) {
            if ($run->isTerminal()) {
                continue;
            }
            $this->traceLog->log(
                TraceType::Defer,
                'PHX-SPAWN-002',
                [
                    'run' => $run->id,
                    'task' => $run->name,
                    'detail' => 'go()-spawned task still live at scope dispose; force-cancelling',
                ],
            );
            $this->supervisor->cancel($run);
            if (Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
        }

        if (Coroutine::getCid() >= 0) {
            $deadline = hrtime(true) + 50_000_000;
            foreach ($this->goSpawns as [$cid, $run]) {
                while (Coroutine::exists($cid) && hrtime(true) < $deadline) {
                    Coroutine::usleep(1_000);
                }
            }
        }

        foreach ($this->goSpawns as [$cid, $run]) {
            if (!$run->isTerminal()) {
                $this->supervisor->reap($run);
            }
        }

        $this->goSpawns = [];
    }

    /**
     * Build a sibling-isolated child scope. Child gets:
     *   - own composite cancellation token (linked to parent's as a source)
     *   - own scoped-instance map (siblings don't share scoped service instances)
     *   - own dispose stack
     *   - own deferredCids list
     *   - own currentRun (parent's run if $inheritParent is non-null, so the
     *     child's first TaskRun gets the right parentId)
     *
     * Shared with parent (per Pool & Scope Discipline #2): graph,
     * singletons (THE pool), trace log, supervisor, attributes (snapshot),
     * singleflightGroup, middlewares, workerDispatch. Pool depth is bounded
     * by the singleton's pool.size, NOT amplified by per-child scopes.
     */
    private function makeChildScope(?TaskRun $inheritParent = null): self
    {
        $childToken = CancellationToken::composite($this->cancellation);
        $child = new self(
            $this->graph,
            $this->singletons,
            $childToken,
            $this->traceLog,
            $this->supervisor,
            $this->attributes,
            $this->singleflightGroup,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
            parentScopeId: $this->scopeIdValue,
            resources: $this->resources,
        );
        $child->currentRun = $inheritParent;
        return $child;
    }

    /**
     * Open a TaskRun, run the task body in this scope's coroutine, finalize
     * the run state from the body's outcome, reap. Called only from
     * execute() at the innermost middleware position.
     *
     * Sibling-isolation invariant (Pool & Scope Discipline #2): the scope
     * passed in already has the right shape for the call site — for
     * top-level execute() it's `$this`, for `concurrent()` children it's
     * a fresh child scope built by the caller.
     */
    private function runSupervised(
        Scopeable|Executable|Closure $task,
        self $scope,
        DispatchMode $mode,
    ): mixed {
        $name = self::resolveTaskName($task);
        $parentRunId = $scope->currentRun?->id;
        $run = $this->supervisor->start($task, $scope, $mode, $name, $parentRunId, token: $scope->cancellation);

        $previousScope = CoroutineScopeRegistry::current();
        $previousRun = $scope->currentRun;

        CoroutineScopeRegistry::install($scope);
        $scope->currentRun = $run;

        try {
            $this->supervisor->markRunning($run);
            $value = self::invokeTask($task, $scope);
            $this->supervisor->complete($run, $value);
            return $value;
        } catch (Cancelled $e) {
            $this->supervisor->cancel($run);
            throw $e;
        } catch (Throwable $e) {
            $this->supervisor->fail($run, $e);
            throw $e;
        } finally {
            $run->cancellation->release();
            $scope->currentRun = $previousRun;
            $this->supervisor->reap($run);
            if ($previousScope !== null) {
                CoroutineScopeRegistry::install($previousScope);
            } else {
                CoroutineScopeRegistry::clear();
            }
        }
    }

    private function runWorkerSupervised(WorkerTask $task, self $scope): mixed
    {
        if ($this->workerDispatch === null) {
            throw new RuntimeException('worker dispatch unavailable');
        }

        $name = self::resolveTaskName($task);
        $parentRun = $scope->currentRun;
        if ($parentRun !== null) {
            $this->supervisor->assertCanEnterWorker($parentRun);
        }

        $run = $this->supervisor->start(
            $task,
            $scope,
            DispatchMode::Worker,
            $name,
            $parentRun?->id,
            token: $scope->cancellation,
        );

        $previousScope = CoroutineScopeRegistry::current();
        $previousRun = $scope->currentRun;
        $clearWait = null;

        CoroutineScopeRegistry::install($scope);
        $scope->currentRun = $run;

        try {
            $this->supervisor->markRunning($run);
            $clearWait = $this->supervisor->beginWait($run, WaitReason::worker('worker', $name));
            $value = $this->workerDispatch->dispatch($task, $scope, $run->cancellation);
            if ($run->cancellation->isCancelled || Coroutine::isCanceled()) {
                throw new Cancelled("worker task {$name} cancelled");
            }
            $this->supervisor->complete($run, $value);
            return $value;
        } catch (Cancelled $e) {
            $this->supervisor->cancel($run);
            throw $e;
        } catch (Throwable $e) {
            $this->supervisor->fail($run, $e);
            throw $e;
        } finally {
            if ($clearWait !== null) {
                $clearWait();
            }
            $run->cancellation->release();
            $scope->currentRun = $previousRun;
            $this->supervisor->reap($run);
            if ($previousScope !== null) {
                CoroutineScopeRegistry::install($previousScope);
            } else {
                CoroutineScopeRegistry::clear();
            }
        }
    }
}
