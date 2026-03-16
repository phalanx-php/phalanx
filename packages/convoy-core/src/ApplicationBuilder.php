<?php

declare(strict_types=1);

namespace Convoy;

use Closure;
use Convoy\Middleware\ServiceTransformationMiddleware;
use Convoy\Middleware\TaskMiddleware;
use Convoy\Service\LazySingleton;
use Convoy\Service\ServiceBundle;
use Convoy\Service\ServiceCatalog;
use Convoy\Service\ServiceGraph;
use Convoy\Service\ServiceGraphCompiler;
use Convoy\Trace\Trace;
use Convoy\Trace\TraceType;

final class ApplicationBuilder
{
    private bool $discover = false;

    private ?Trace $trace = null;

    private WorkerDispatch|Closure|null $workerDispatch = null;

    /** @var list<ServiceBundle> */
    private array $providers = [];

    /** @var list<TaskMiddleware> */
    private array $taskInterceptors = [];

    /** @var list<ServiceTransformationMiddleware> */
    private array $serviceMiddleware = [];

    /** @param array<string, mixed> $context */
    public function __construct(
        private readonly array $context,
    ) {
    }

    public function serviceMiddleware(ServiceTransformationMiddleware ...$middleware): self
    {
        foreach ($middleware as $mw) {
            $this->serviceMiddleware[] = $mw;
        }

        return $this;
    }

    public function taskMiddleware(TaskMiddleware ...$interceptors): self
    {
        foreach ($interceptors as $interceptor) {
            $this->taskInterceptors[] = $interceptor;
        }

        return $this;
    }

    public function discover(): self
    {
        $this->discover = true;
        return $this;
    }

    public function providers(ServiceBundle ...$providers): self
    {
        foreach ($providers as $provider) {
            $this->providers[] = $provider;
        }

        return $this;
    }

    public function withTrace(Trace $trace): self
    {
        $this->trace = $trace;
        return $this;
    }

    /** @param WorkerDispatch|Closure(ServiceGraph, LazySingleton): WorkerDispatch $dispatch */
    public function withWorkerDispatch(WorkerDispatch|Closure $dispatch): self
    {
        $this->workerDispatch = $dispatch;
        return $this;
    }

    public function compile(): Application
    {
        $trace = $this->trace ?? Trace::fromContext($this->context);
        $trace->log(TraceType::LifecycleStartup, 'compiling');

        $registry = new ServiceCatalog();

        if ($this->discover) {
            $this->loadDiscoveredProviders();
        }

        foreach ($this->providers as $provider) {
            $provider->services($registry, $this->context);
        }

        $compiler = new ServiceGraphCompiler();
        $graph = $compiler->compile($registry, $this->serviceMiddleware, $this->context);

        $singletons = new LazySingleton($graph, $trace);

        $workerDispatch = $this->workerDispatch instanceof Closure
            ? ($this->workerDispatch)($graph, $singletons)
            : $this->workerDispatch;

        return Application::create($graph, $singletons, $trace, $this->providers, $this->taskInterceptors, $workerDispatch);
    }

    private function loadDiscoveredProviders(): void
    {
        $vendorPath = $this->context['vendor_path']
            ?? (isset($this->context['project_dir']) ? $this->context['project_dir'] . '/vendor' : null);

        if ($vendorPath === null) {
            return;
        }

        $providersFile = $vendorPath . '/convoy/providers.php';
        if (!file_exists($providersFile)) {
            return;
        }
        $providers = require $providersFile;
        if (!is_array($providers)) {
            return;
        }
        foreach ($providers as $providerClass) {
            if (!class_exists($providerClass)) {
                continue;
            }

            $provider = new $providerClass();

            if ($provider instanceof ServiceBundle) {
                $this->providers[] = $provider;
            }
        }
    }
}
