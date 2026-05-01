<?php

declare(strict_types=1);

namespace Phalanx;

use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Service\LazySingleton;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\ServiceTransformationMiddleware;
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

    public function compile(): Application
    {
        $catalog = new ServiceCatalog($this->context);
        foreach ($this->providers as $provider) {
            $provider->services($catalog, $this->context);
        }
        $graph = $catalog->compile();
        $singletons = new LazySingleton($graph);
        return new Application(
            $graph,
            $singletons,
            $this->trace ?? new Trace(),
            $this->providers,
            $this->serviceMiddlewares,
            $this->taskMiddlewares,
            $this->workerDispatch,
        );
    }
}
