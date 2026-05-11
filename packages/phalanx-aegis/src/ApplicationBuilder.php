<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessReport;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\ServiceLifetime;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Supervisor\SwooleTableLedger;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use Phalanx\Worker\WorkerDispatch;

/**
 * Low-level Aegis host builder.
 *
 * Package and example bootstraps should prefer their package facade builders:
 * `Phalanx\Athena\Athena::starting()`,
 * `Phalanx\Archon\Application\Archon::starting()`, or
 * `Phalanx\Stoa\Stoa::starting()`.
 */
class ApplicationBuilder
{
    /** @var list<ServiceBundle> */
    private array $providers = [];

    /** @var list<ServiceTransformationMiddleware> */
    private array $serviceMiddlewares = [];

    /** @var list<TaskMiddleware> */
    private array $taskMiddlewares = [];

    private ?Trace $trace = null;

    private ?LedgerStorage $ledger = null;

    private ?bool $strictRuntimeHooks = null;

    private ?RuntimePolicy $runtimePolicy = null;

    private ?WorkerDispatch $workerDispatch = null;

    private ?BootHarnessReport $lastBootReport = null;

    public function __construct(private readonly AppContext $context)
    {
    }

    public function providers(ServiceBundle ...$providers): self
    {
        $this->providers = array_values([...$this->providers, ...$providers]);
        return $this;
    }

    public function serviceMiddleware(ServiceTransformationMiddleware ...$middlewares): self
    {
        $this->serviceMiddlewares = array_values([...$this->serviceMiddlewares, ...$middlewares]);
        return $this;
    }

    public function taskMiddleware(TaskMiddleware ...$middlewares): self
    {
        $this->taskMiddlewares = array_values([...$this->taskMiddlewares, ...$middlewares]);
        return $this;
    }

    public function withTrace(Trace $trace): self
    {
        $this->trace = $trace;
        return $this;
    }

    public function withWorkerDispatch(WorkerDispatch $dispatch): self
    {
        $this->workerDispatch = $dispatch;
        return $this;
    }

    public function withRuntimePolicy(RuntimePolicy $policy): self
    {
        $this->runtimePolicy = $policy;
        return $this;
    }

    public function withRuntimeHooksStrict(bool $strict): self
    {
        $this->strictRuntimeHooks = $strict;
        return $this;
    }

    /**
     * Override the supervisor's ledger backend. The runtime default is the
     * primitive Swoole table ledger; InProcessLedger is only for narrow tests.
     */
    public function withLedger(LedgerStorage $ledger): self
    {
        $this->ledger = $ledger;
        return $this;
    }

    /**
     * The BootHarnessReport from the most recent compile() call.
     * Null before compile() has been called.
     */
    public function lastBootReport(): ?BootHarnessReport
    {
        return $this->lastBootReport;
    }

    public function compile(): Application
    {
        $runner = new BootHarnessRunner();
        $this->lastBootReport = $runner->run($this->context, $this->providers, $this->vendorDir());

        $runtimeContext = new RuntimeContext(RuntimeMemory::fromContext($this->context));
        $trace = $this->trace ?? new Trace();
        $catalog = new ServiceCatalog($this->context);
        $catalog->singleton(RuntimeContext::class)
            ->factory(static fn(): RuntimeContext => $runtimeContext);
        $catalog->singleton(Trace::class)
            ->factory(static fn(): Trace => $trace);
        $catalog->singleton(HandlerResolver::class)
            ->factory(static fn(): HandlerResolver => new HandlerResolver());
        foreach ($this->providers as $provider) {
            $provider->services($catalog, $this->context);
        }
        $graph = $catalog->compile();
        $singletons = new LazySingleton($graph);
        $ledger = $this->ledger ?? new SwooleTableLedger(memory: $runtimeContext->memory);
        $runtimeContext->memory->events->listen(static function ($event) use ($trace): void {
            $trace->log(
                TraceType::Lifecycle,
                $event->type,
                [
                    'sequence' => $event->sequence,
                    'scope' => $event->scopeId,
                    'run' => $event->runId,
                    'state' => $event->state,
                    'value_a' => $event->valueA,
                    'value_b' => $event->valueB,
                ],
            );
        });
        $supervisor = new Supervisor($ledger, $trace);
        $resolvedWorkerDispatch = $this->workerDispatch;
        $workerDispatchType = $graph->alias(WorkerDispatch::class);
        $workerDispatchConfig = $graph->configs[$workerDispatchType] ?? null;
        if ($resolvedWorkerDispatch === null && $workerDispatchConfig !== null) {
            if ($workerDispatchConfig->lifetime !== ServiceLifetime::Singleton) {
                throw new \RuntimeException(
                    'WorkerDispatch services must be registered as singletons so the process pool is application-owned.',
                );
            }

            $resolverScope = new ExecutionLifecycleScope(
                $graph,
                $singletons,
                CancellationToken::create(),
                $trace,
                $supervisor,
            );
            try {
                $resolvedWorkerDispatch = $resolverScope->service(WorkerDispatch::class);
            } finally {
                $resolverScope->dispose();
            }
        }
        $runtimePolicy = $this->runtimePolicy ?? RuntimePolicy::fromContext($this->context);
        $strictRuntimeHooks = $this->strictRuntimeHooks ?? $this->context->bool(
            RuntimePolicy::CONTEXT_STRICT_HOOKS,
            true,
        );

        return new Application(
            $runtimeContext,
            $graph,
            $singletons,
            $trace,
            $supervisor,
            $this->providers,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $resolvedWorkerDispatch,
            $runtimePolicy,
            $strictRuntimeHooks,
        );
    }

    public function run(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed
    {
        return $this->compile()->run($task, $token);
    }

    private function vendorDir(): ?string
    {
        // Resolve from the Composer autoloader registration point so the path
        // is stable regardless of the process working directory.
        foreach ([
            dirname(__DIR__, 3) . '/vendor',
            dirname(__DIR__, 5) . '/vendor',
        ] as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
