<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Closure;
use Phalanx\Application;
use Phalanx\ApplicationBuilder;
use Phalanx\Boot\AppContext;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Service\ServiceBundle;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerDispatch;

/**
 * Facade builder for Athena agent applications.
 *
 * Bootstrap files should enter through `Athena::starting($context)` so AI
 * provider and swarm services are registered with the Aegis host.
 */
final class AthenaApplicationBuilder
{
    private ApplicationBuilder $app;

    private bool $aiServicesAdded = false;

    public function __construct(
        AppContext $context = new AppContext(),
    ) {
        $this->app = Application::starting($context);
    }

    public function providers(ServiceBundle ...$providers): self
    {
        $this->app->providers(...$providers);
        return $this;
    }

    public function serviceMiddleware(ServiceTransformationMiddleware ...$middlewares): self
    {
        $this->app->serviceMiddleware(...$middlewares);
        return $this;
    }

    public function taskMiddleware(TaskMiddleware ...$middlewares): self
    {
        $this->app->taskMiddleware(...$middlewares);
        return $this;
    }

    public function withTrace(Trace $trace): self
    {
        $this->app->withTrace($trace);
        return $this;
    }

    public function withWorkerDispatch(WorkerDispatch $dispatch): self
    {
        $this->app->withWorkerDispatch($dispatch);
        return $this;
    }

    public function withRuntimePolicy(RuntimePolicy $policy): self
    {
        $this->app->withRuntimePolicy($policy);
        return $this;
    }

    public function withRuntimeHooksStrict(bool $strict): self
    {
        $this->app->withRuntimeHooksStrict($strict);
        return $this;
    }

    public function withLedger(LedgerStorage $ledger): self
    {
        $this->app->withLedger($ledger);
        return $this;
    }

    public function build(): AthenaApplication
    {
        $this->installAiServices();
        return new AthenaApplication($this->app->compile());
    }

    public function run(Scopeable|Executable|Closure $task, ?CancellationToken $token = null): mixed
    {
        return $this->build()->run($task, $token);
    }

    private function installAiServices(): void
    {
        if ($this->aiServicesAdded) {
            return;
        }

        $this->app->providers(new AiServiceBundle());
        $this->aiServicesAdded = true;
    }
}
