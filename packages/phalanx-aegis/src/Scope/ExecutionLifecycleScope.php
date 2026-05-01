<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Phalanx\Cancellation\AggregateException;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Concurrency\Co;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\Settlement;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Concurrency\SingleflightGroup;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Service\CompiledServiceConfig;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceGraph;
use Phalanx\Service\ServiceLifetime;
use Phalanx\Service\ServiceTransformationMiddleware;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use Phalanx\Worker\WorkerDispatch;
use Closure;
use OpenSwoole\Core\Coroutine\WaitGroup;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use RuntimeException;
use Throwable;

/**
 * Concrete ExecutionScope on Swoole.
 *
 * Phase 0+1 surface live: full TaskExecutor (concurrent, race, any, map,
 * series, waterfall, settle, timeout, retry, delay, defer, singleflight)
 * plus Scope/Suspendable/Cancellable/Disposable/StreamContext basics.
 * inWorker() throws until Phase 4.
 *
 * Cancellation translation (per Phase 0 substrate finding): OpenSwoole's
 * Coroutine::cancel does not raise an exception; it interrupts I/O and sets
 * isCanceled() to true. Co::sleep and concurrency primitive child wrappers
 * check this and translate to Cancelled.
 */
class ExecutionLifecycleScope implements ExecutionScope
{
    public bool $isCancelled {
        get => $this->cancellation->isCancelled;
    }

    /** @var array<class-string, object> */
    private array $scopedInstances = [];

    /** @var list<class-string> */
    private array $scopedCreationOrder = [];

    /** @var list<Closure(): void> */
    private array $disposeStack = [];

    /** @var list<int> */
    private array $deferredCids = [];

    private bool $disposed = false;

    private readonly SingleflightGroup $singleflightGroup;

    /**
     * @param array<string, mixed> $attributes
     * @param list<ServiceTransformationMiddleware> $serviceMiddlewares
     * @param list<TaskMiddleware> $taskMiddlewares
     */
    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly CancellationToken $cancellation,
        private readonly Trace $traceLog,
        private array $attributes = [],
        ?SingleflightGroup $singleflight = null,
        private readonly array $serviceMiddlewares = [],
        private readonly array $taskMiddlewares = [],
        private readonly ?WorkerDispatch $workerDispatch = null,
    ) {
        $this->singleflightGroup = $singleflight ?? new SingleflightGroup();
    }

    public function service(string $type): object
    {
        $this->throwIfCancelled();
        $resolved = $this->graph->alias($type);

        if ($this->graph->hasContextConfig($type)) {
            return $this->graph->contextConfig($type);
        }

        $config = $this->graph->resolve($type);

        $build = (fn(): object => $this->build($config));

        if ($config->lifetime === ServiceLifetime::Singleton) {
            return $this->singletons->get($resolved, fn() => $this->runMiddleware($resolved, $build));
        }

        if (isset($this->scopedInstances[$resolved])) {
            return $this->scopedInstances[$resolved];
        }

        $instance = $this->runMiddleware($resolved, $build);
        $this->scopedInstances[$resolved] = $instance;
        $this->scopedCreationOrder[] = $resolved;
        return $instance;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): ExecutionScope
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;
        return new self(
            $this->graph,
            $this->singletons,
            $this->cancellation,
            $this->traceLog,
            $attributes,
            $this->singleflightGroup,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
        );
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
    }

    public function call(Closure $fn): mixed
    {
        $this->throwIfCancelled();
        $cid = Coroutine::getCid();
        $unregister = $this->cancellation->onCancel(static function () use ($cid): void {
            if ($cid > 0 && Coroutine::exists($cid)) {
                Coroutine::cancel($cid);
            }
        });
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
            $unregister();
        }
    }

    public function execute(Scopeable|Executable|Closure $task): mixed
    {
        $this->throwIfCancelled();
        $previous = CoroutineScopeRegistry::current();
        CoroutineScopeRegistry::install($this);
        try {
            $invoke = static fn(ExecutionScope $s): mixed => ($task)($s);
            return $this->runTaskMiddleware($task, $invoke);
        } finally {
            if ($previous !== null) {
                CoroutineScopeRegistry::install($previous);
            } else {
                CoroutineScopeRegistry::clear();
            }
        }
    }

    public function executeFresh(Scopeable|Executable|Closure $task): mixed
    {
        $child = new self(
            $this->graph,
            $this->singletons,
            $this->cancellation,
            $this->traceLog,
            $this->attributes,
            $this->singleflightGroup,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
        );
        try {
            return $child->execute($task);
        } finally {
            $child->dispose();
        }
    }

    public function concurrent(array $tasks): array
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

        $unregister = $this->cancellation->onCancel(static function () use (&$cids): void {
            foreach ($cids as $cid) {
                if (Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            }
        });

        try {
            foreach ($tasks as $key => $task) {
                $self = $this;
                $cid = Coroutine::create(static function () use ($self, $task, $key, $wg, &$results, &$errors): void {
                    CoroutineScopeRegistry::install($self);
                    try {
                        $results[$key] = ($task)($self);
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
            $unregister();
        }

        $ordered = [];
        foreach (array_keys($tasks) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }

    public function race(array $tasks): mixed
    {
        $this->throwIfCancelled();
        if ($tasks === []) {
            throw new RuntimeException('race(): empty task list');
        }

        $count = count($tasks);
        $channel = new Channel($count);
        $cids = [];

        $unregister = $this->cancellation->onCancel(static function () use (&$cids): void {
            foreach ($cids as $cid) {
                if (Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            }
        });

        $self = $this;
        try {
            foreach ($tasks as $key => $task) {
                $cid = Coroutine::create(static function () use ($self, $task, $key, $channel): void {
                    CoroutineScopeRegistry::install($self);
                    try {
                        $value = ($task)($self);
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

            $first = $channel->pop();

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
            $unregister();
            $channel->close();
        }
    }

    public function any(array $tasks): mixed
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

        $unregister = $this->cancellation->onCancel(static function () use (&$cids): void {
            foreach ($cids as $cid) {
                if (Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            }
        });

        $self = $this;
        try {
            foreach ($tasks as $key => $task) {
                $cid = Coroutine::create(static function () use ($self, $task, $key, $channel): void {
                    CoroutineScopeRegistry::install($self);
                    try {
                        $value = ($task)($self);
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

            while ($remaining-- > 0) {
                [$kind, $key, $value] = $channel->pop();
                if ($kind === 'ok') {
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
            $unregister();
            $channel->close();
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

        $unregister = $this->cancellation->onCancel(static function () use (&$cids): void {
            foreach ($cids as $cid) {
                if (Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            }
        });

        $self = $this;
        try {
            foreach ($itemsArr as $key => $item) {
                $cid = Coroutine::create(static function () use ($self, $fn, $onEach, $item, $key, $sem, $wg, &$results, &$errors): void {
                    CoroutineScopeRegistry::install($self);
                    $sem->push(1);
                    try {
                        $task = $fn($item);
                        $value = ($task)($self);
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
            $unregister();
            $sem->close();
        }

        $ordered = [];
        foreach (array_keys($itemsArr) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }
        return $ordered;
    }

    public function series(array $tasks): array
    {
        $results = [];
        foreach ($tasks as $key => $task) {
            $this->throwIfCancelled();
            $results[$key] = ($task)($this);
        }
        return $results;
    }

    public function waterfall(array $tasks): mixed
    {
        $previous = null;
        $first = true;
        foreach ($tasks as $task) {
            $this->throwIfCancelled();
            $stepScope = $first
                ? $this
                : $this->withAttribute('_waterfall_previous', $previous);
            $first = false;
            $previous = $stepScope->executeFresh($task);
        }
        return $previous;
    }

    public function settle(array $tasks): SettlementBag
    {
        $this->throwIfCancelled();
        if ($tasks === []) {
            return new SettlementBag([]);
        }

        $wg = new WaitGroup();
        $wg->add(count($tasks));
        $bag = [];
        $cids = [];

        $unregister = $this->cancellation->onCancel(static function () use (&$cids): void {
            foreach ($cids as $cid) {
                if (Coroutine::exists($cid)) {
                    Coroutine::cancel($cid);
                }
            }
        });

        $self = $this;
        try {
            foreach ($tasks as $key => $task) {
                $cid = Coroutine::create(static function () use ($self, $task, $key, $wg, &$bag): void {
                    CoroutineScopeRegistry::install($self);
                    try {
                        $value = ($task)($self);
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
            $unregister();
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
            $this->attributes,
            $this->singleflightGroup,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
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
                return ($task)($this);
            } catch (Cancelled $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastError = $e;
                if (!$policy->shouldRetry($e)) {
                    throw $e;
                }
                if ($attempt < $policy->attempts - 1) {
                    $this->traceLog->log(TraceType::Retry, 'retry', ['attempt' => $attempt + 1, 'error' => $e->getMessage()]);
                    Co::sleep($policy->calculateDelay($attempt) / 1000);
                }
            }
        }
        throw $lastError ?? new RuntimeException('retry: no attempts executed');
    }

    public function delay(float $seconds): void
    {
        $this->call(static function () use ($seconds): void {
            Co::sleep($seconds);
        });
    }

    public function defer(Scopeable|Executable|Closure $task): void
    {
        if ($this->disposed) {
            return;
        }
        $self = $this;
        $traceLog = $this->traceLog;
        $cid = Coroutine::create(static function () use ($self, $task, $traceLog): void {
            CoroutineScopeRegistry::install($self);
            try {
                ($task)($self);
            } catch (Throwable $e) {
                $traceLog->log(TraceType::Defer, 'defer.error', ['error' => $e->getMessage()]);
            } finally {
                CoroutineScopeRegistry::clear();
            }
        });
        if ($cid !== false) {
            $this->deferredCids[] = $cid;
        }
    }

    public function singleflight(string $key, Scopeable|Executable|Closure $task): mixed
    {
        $this->throwIfCancelled();
        $self = $this;
        return $this->singleflightGroup->do($key, static fn() => ($task)($self));
    }

    public function inWorker(Scopeable|Executable|Closure $task): mixed
    {
        if ($this->workerDispatch === null) {
            throw new RuntimeException('inWorker(): no WorkerDispatch configured. Use ApplicationBuilder::withWorkerDispatch().');
        }
        if ($task instanceof Closure) {
            throw new RuntimeException('inWorker(): Closure cannot cross process boundary; pass a Scopeable|Executable instance.');
        }
        return $this->workerDispatch->dispatch($task, $this->cancellation);
    }

    /**
     * Wrap the build closure in the middleware chain. Middlewares are invoked
     * in registration order (first-registered runs outermost). Each calls
     * $next() to descend; the innermost call invokes $build directly.
     *
     * @param Closure(): object $build
     */
    private function runMiddleware(string $type, Closure $build): object
    {
        if ($this->serviceMiddlewares === []) {
            return $build();
        }

        $scope = $this;
        $next = $build;
        foreach (array_reverse($this->serviceMiddlewares) as $middleware) {
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
    private function runTaskMiddleware(Scopeable|Executable|Closure $task, Closure $invoke): mixed
    {
        if ($this->taskMiddlewares === []) {
            return $invoke($this);
        }

        $next = $invoke;
        foreach (array_reverse($this->taskMiddlewares) as $middleware) {
            $current = $next;
            $next = static fn(ExecutionScope $s): mixed => $middleware->handle($task, $s, $current);
        }
        return $next($this);
    }

    private function build(CompiledServiceConfig $config): object
    {
        if ($config->factoryFn === null) {
            throw new RuntimeException("Service {$config->type} has no factory");
        }
        $deps = [];
        foreach ($config->needsTypes as $needed) {
            $deps[] = $this->service($needed);
        }
        $this->traceLog->log(TraceType::ServiceResolve, $config->type);
        $instance = ($config->factoryFn)(...$deps);
        foreach ($config->onInitHooks as $hook) {
            $hook($instance);
        }
        return $instance;
    }
}
