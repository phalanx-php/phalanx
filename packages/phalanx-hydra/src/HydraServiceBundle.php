<?php

declare(strict_types=1);

namespace Phalanx\Hydra;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Worker\WorkerDispatch;

class HydraServiceBundle extends ServiceBundle
{
    public function __construct(
        private ?ParallelConfig $config = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $parallelConfig = $this->config ?? ParallelConfig::fromContext($context);

        $services->config(ParallelConfig::class, static fn(): ParallelConfig => $parallelConfig);
        $services->singleton(WorkerDispatch::class)
            ->needs(ParallelConfig::class)
            ->factory(static fn(ParallelConfig $config): WorkerDispatch => new ParallelWorkerDispatch($config))
            ->onShutdown(static function (WorkerDispatch $dispatch): void {
                $dispatch->shutdown();
            });
    }
}
