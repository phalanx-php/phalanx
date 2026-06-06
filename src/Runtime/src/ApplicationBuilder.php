<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarnessReport;
use Phalanx\Boot\BootHarnessRunner;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Config\Config;
use Phalanx\Config\ConfigCatalog;
use Phalanx\Config\ConfigFactory;
use Phalanx\Config\ConfigValidator;
use Phalanx\Exception\ErrorRegistry;
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
use Phalanx\Support\PackagePaths;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Trace\TraceType;
use Phalanx\Worker\WorkerDispatch;

/**
 * Low-level Runtime host builder.
 *
 * Package and example bootstraps should prefer their package module entry builders:
 * `Phalanx\Agents\Agents::starting()`,
 * `Phalanx\Console\Console::starting()`, or
 * `Phalanx\Http\Server::starting()`.
 */
class ApplicationBuilder
{
    private(set) int $taskRunPoolCapacity = 256;

    private(set) int $scopeFramePoolCapacity = 256;

    private(set) int $tokenPoolCapacity = 512;

    /** @var list<ServiceBundle> */
    private array $providers = [];

    /** @var list<ServiceTransformationMiddleware> */
    private array $serviceMiddlewares = [];

    /** @var list<TaskMiddleware> */
    private array $taskMiddlewares = [];

    /** @var list<\Phalanx\Exception\ErrorHandler> */
    private array $errorHandlers = [];

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

    public function withErrorHandler(\Phalanx\Exception\ErrorHandler ...$handlers): self
    {
        $this->errorHandlers = array_values([...$this->errorHandlers, ...$handlers]);

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

    public function withPoolCapacities(int $taskRun, int $scopeFrame, int $token): self
    {
        $this->taskRunPoolCapacity = $taskRun;
        $this->scopeFramePoolCapacity = $scopeFrame;
        $this->tokenPoolCapacity = $token;

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

        $trace = $this->trace ?? new Trace();
        $runtimeContext = new RuntimeContext(RuntimeMemory::fromContext($this->context));

        $catalog = new ServiceCatalog();
        $catalog
            ->singleton(RuntimeContext::class)
            ->factory(static fn(): RuntimeContext => $runtimeContext);

        $catalog
            ->singleton(Trace::class)
            ->factory(static fn(): Trace => $trace);

        $catalog
            ->singleton(HandlerResolver::class)
            ->factory(static fn(): HandlerResolver => new HandlerResolver());

        $errorHandlers = $this->errorHandlers;
        $catalog
            ->singleton(ErrorRegistry::class)
            ->factory(static fn() => new ErrorRegistry($errorHandlers));

        foreach ($this->providers as $provider) {
            $provider->services($catalog, $this->context);
        }

        $this->autoRegisterConfigs($catalog);

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

        $supervisor = new Supervisor(
            $ledger,
            $trace,
            $this->taskRunPoolCapacity,
            $this->scopeFramePoolCapacity,
            $this->tokenPoolCapacity,
        );

        $resolvedWorkerDispatch = $this->workerDispatch;
        $workerDispatchType = $graph->alias(WorkerDispatch::class);
        $workerDispatchConfig = $graph->configs[$workerDispatchType] ?? null;

        if ($resolvedWorkerDispatch === null && $workerDispatchConfig !== null) {
            if ($workerDispatchConfig->lifetime !== ServiceLifetime::Singleton) {
                throw new \RuntimeException(
                    'WorkerDispatch services must be registered as singletons'
                    . ' so the process pool is application-owned.',
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

    private function autoRegisterConfigs(ServiceCatalog $catalog): void
    {
        /** @var list<class-string<Config>> $configClasses */
        $configClasses = [];
        foreach ($this->providers as $provider) {
            foreach ($provider::configs() as $configClass) {
                if (!in_array($configClass, $configClasses, true)) {
                    $configClasses[] = $configClass;
                }
            }
        }

        $contextValues = $this->context->values;
        $catalog->singleton(ConfigFactory::class)
            ->factory(static fn(): ConfigFactory => ConfigFactory::fromContext($contextValues));

        $catalog->singleton(ConfigCatalog::class)
            ->factory(static fn(): ConfigCatalog => ConfigCatalog::of(...$configClasses));

        $catalog->singleton(ConfigValidator::class)
            ->needs(ConfigFactory::class)
            ->factory(static fn(ConfigFactory $factory): ConfigValidator => new ConfigValidator($factory));

        foreach ($configClasses as $configClass) {
            if ($catalog->has($configClass)) {
                continue;
            }

            $catalog->singleton($configClass)
                ->needs(ConfigFactory::class)
                ->factory(static fn(ConfigFactory $factory): Config => $factory->hydrate($configClass));
        }
    }

    private function vendorDir(): ?string
    {
        return PackagePaths::firstExistingDirectory(
            PackagePaths::ancestorCandidates(__DIR__, 'vendor'),
        );
    }
}
