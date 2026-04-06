<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Concurrency\CancellationToken;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceGraph;
use Phalanx\Task\ManagedResource;
use Phalanx\Trace\Trace;

final class Application implements AppHost
{
    private bool $started = false;

    private function __construct(
        private readonly ServiceGraph $graph,
        private readonly LazySingleton $singletons,
        private readonly Trace $trace,
        /** @var list<ServiceBundle> */
        private readonly array $serviceProviders,
        /** @var list<TaskMiddleware> */
        private readonly array $taskInterceptors,
        private readonly ?WorkerDispatch $workerDispatch = null,
    ) {
    }

    /**
     * @param list<ServiceBundle> $providers
     * @param list<TaskMiddleware> $taskInterceptors
     */
    public static function create(
        ServiceGraph $graph,
        LazySingleton $singletons,
        Trace $trace,
        array $providers,
        array $taskInterceptors,
        ?WorkerDispatch $workerDispatch = null,
    ): self {
        return new self($graph, $singletons, $trace, $providers, $taskInterceptors, $workerDispatch);
    }

    /** @param array<string, mixed> $context */
    public static function starting(array $context = []): ApplicationBuilder
    {
        return new ApplicationBuilder($context);
    }

    /** @return list<ServiceBundle> */
    public function providers(): array
    {
        return $this->serviceProviders;
    }

    public function createScope(?CancellationToken $token = null): ExecutionScope
    {
        return new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            $token ?? CancellationToken::none(),
            $this->trace,
            $this->taskInterceptors,
            workerDispatch: $this->workerDispatch,
        );
    }

    public function scope(): Scope
    {
        return new ExecutionLifecycleScope(
            $this->graph,
            $this->singletons,
            CancellationToken::none(),
            $this->trace,
            $this->taskInterceptors,
            workerDispatch: $this->workerDispatch,
        );
    }

    public function startup(): static
    {
        if ($this->started) {
            return $this;
        }

        $this->started = true;
        ManagedResource::enableShutdownFlush();
        $this->singletons->startup();

        return $this;
    }

    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        $this->singletons->shutdown();
        $this->started = false;
    }

    public function trace(): Trace
    {
        return $this->trace;
    }

    public function graph(): ServiceGraph
    {
        return $this->graph;
    }
}
