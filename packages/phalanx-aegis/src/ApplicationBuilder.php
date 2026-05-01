<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Handler\HandlerResolver;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\ServiceTransformationMiddleware;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Supervisor\Supervisor;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerDispatch;

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

    private ?WorkerDispatch $workerDispatch = null;

    /** @param array<string, mixed> $context */
    public function __construct(private readonly array $context)
    {
    }

    public function providers(ServiceBundle ...$providers): self
    {
        $this->providers = [...$this->providers, ...$providers];
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

    /**
     * Override the supervisor's ledger backend. Defaults to InProcessLedger
     * (PHP array, lock-free under cooperative scheduling). Swap to
     * SwooleTableLedger (when implemented) for cross-process worker
     * visibility into the live TaskRun graph.
     */
    public function withLedger(LedgerStorage $ledger): self
    {
        $this->ledger = $ledger;
        return $this;
    }

    public function compile(): Application
    {
        $catalog = new ServiceCatalog($this->context);
        $catalog->singleton(HandlerResolver::class)
            ->factory(static fn(): HandlerResolver => new HandlerResolver());
        foreach ($this->providers as $provider) {
            $provider->services($catalog, $this->context);
        }
        $graph = $catalog->compile();
        $singletons = new LazySingleton($graph);
        $trace = $this->trace ?? new Trace();
        $supervisor = new Supervisor($this->ledger ?? new InProcessLedger(), $trace);
        return new Application(
            $graph,
            $singletons,
            $trace,
            $supervisor,
            $this->providers,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
        );
    }
}
