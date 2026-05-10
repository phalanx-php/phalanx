<?php

declare(strict_types=1);

namespace Phalanx;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\CoroutineRuntime;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Runtime\RuntimeHooks;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceGraph;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerDispatch;

class Application implements AppHost
{
    private bool $started = false;

    private readonly RuntimePolicy $runtimePolicy;

    /**
     * @param list<ServiceBundle> $providers
     * @param list<ServiceTransformationMiddleware> $serviceMiddlewares
     * @param list<TaskMiddleware> $taskMiddlewares
     */
    public function __construct(
        private readonly RuntimeContext $runtime,
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly Trace $traceLog,
        private readonly Supervisor $supervisor,
        private readonly array $providers,
        private readonly array $serviceMiddlewares = [],
        private readonly array $taskMiddlewares = [],
        private readonly ?WorkerDispatch $workerDispatch = null,
        ?RuntimePolicy $runtimePolicy = null,
        private readonly bool $strictRuntimeHooks = true,
    ) {
        $this->runtimePolicy = $runtimePolicy ?? RuntimePolicy::phalanxManaged();
    }

    /** @param array<string,mixed> $context */
    public static function starting(array $context = []): ApplicationBuilder
    {
        return new ApplicationBuilder(new AppContext($context));
    }

    public function providers(): array
    {
        return $this->providers;
    }

    public function supervisor(): Supervisor
    {
        return $this->supervisor;
    }

    public function runtime(): RuntimeContext
    {
        return $this->runtime;
    }

    public function createScope(?CancellationToken $token = null): ExecutionScope
    {
        $this->ensureRuntimeHooks();

        return new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            $token ?? CancellationToken::create(),
            $this->traceLog,
            $this->supervisor,
            serviceMiddlewares: $this->serviceMiddlewares,
            taskMiddlewares: $this->taskMiddlewares,
            workerDispatch: $this->workerDispatch,
        );
    }

    public function scope(): Scope
    {
        return $this->createScope();
    }

    public function run(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed
    {
        $self = $this;

        return CoroutineRuntime::run(
            $this->runtimePolicy,
            static function () use ($self, $task, $token): mixed {
                $self->startup();

                try {
                    return $self->executeScoped($task, $token);
                } finally {
                    $self->shutdown();
                }
            },
            $this->strictRuntimeHooks,
        );
    }

    public function scoped(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed
    {
        $self = $this;

        return CoroutineRuntime::run(
            $this->runtimePolicy,
            static function () use ($self, $task, $token): mixed {
                $self->startup();

                return $self->executeScoped($task, $token);
            },
            $this->strictRuntimeHooks,
        );
    }

    public function startup(): static
    {
        if ($this->started) {
            return $this;
        }
        $this->ensureRuntimeHooks();
        $this->started = true;
        $rootScope = $this->createScope();
        try {
            $this->singletons->startupEager(static fn(string $type): object => $rootScope->service($type));
        } finally {
            $rootScope->dispose();
        }
        return $this;
    }

    public function shutdown(): void
    {
        $this->workerDispatch?->shutdown();
        $this->singletons->shutdown();
        $this->runtime->memory->shutdown();
        $this->started = false;
    }

    public function trace(): Trace
    {
        return $this->traceLog;
    }

    private function ensureRuntimeHooks(): void
    {
        RuntimeHooks::ensure($this->runtimePolicy, $this->strictRuntimeHooks);
    }

    private function executeScoped(Scopeable|Executable|Closure $task, ?CancellationToken $token): mixed
    {
        $scope = $this->createScope($token);

        try {
            return $scope->execute($task);
        } finally {
            $scope->dispose();
        }
    }
}
