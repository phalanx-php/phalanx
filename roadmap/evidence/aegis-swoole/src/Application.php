<?php

declare(strict_types=1);

namespace AegisSwoole;

use AegisSwoole\Cancellation\CancellationToken;
use AegisSwoole\Middleware\TaskMiddleware;
use AegisSwoole\Scope\ExecutionLifecycleScope;
use AegisSwoole\Scope\ExecutionScope;
use AegisSwoole\Scope\Scope;
use AegisSwoole\Service\LazySingleton;
use AegisSwoole\Service\ServiceBundle;
use AegisSwoole\Service\ServiceGraph;
use AegisSwoole\Service\ServiceTransformationMiddleware;
use AegisSwoole\Trace\Trace;
use AegisSwoole\Worker\WorkerDispatch;

class Application implements AppHost
{
    private bool $started = false;

    /**
     * @param list<ServiceBundle> $providers
     * @param list<ServiceTransformationMiddleware> $serviceMiddlewares
     * @param list<TaskMiddleware> $taskMiddlewares
     */
    public function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly Trace $traceLog,
        private readonly array $providers,
        private readonly array $serviceMiddlewares = [],
        private readonly array $taskMiddlewares = [],
        private readonly ?WorkerDispatch $workerDispatch = null,
    ) {
    }

    /** @param array<string, mixed> $context */
    public static function starting(array $context = []): ApplicationBuilder
    {
        return new ApplicationBuilder($context);
    }

    public function providers(): array
    {
        return $this->providers;
    }

    public function createScope(?CancellationToken $token = null): ExecutionScope
    {
        return new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            $token ?? CancellationToken::create(),
            $this->traceLog,
            serviceMiddlewares: $this->serviceMiddlewares,
            taskMiddlewares: $this->taskMiddlewares,
            workerDispatch: $this->workerDispatch,
        );
    }

    public function scope(): Scope
    {
        return $this->createScope();
    }

    public function startup(): static
    {
        if ($this->started) {
            return $this;
        }
        $this->started = true;
        $rootScope = $this->createScope();
        try {
            $this->singletons->startupEager(static fn(string $type) => $rootScope->service($type));
        } finally {
            $rootScope->dispose();
        }
        return $this;
    }

    public function shutdown(): void
    {
        $this->workerDispatch?->shutdown();
        $this->singletons->shutdown();
        $this->started = false;
    }

    public function trace(): Trace
    {
        return $this->traceLog;
    }
}
