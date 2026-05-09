<?php

declare(strict_types=1);

namespace Phalanx\Demos\Hydra\BasicWorkers;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class HydraDemoServiceBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(HydraGreetingServiceImpl::class)
            ->factory(static fn(): HydraGreetingServiceImpl => new HydraGreetingServiceImpl());
        $services->alias(HydraGreetingService::class, HydraGreetingServiceImpl::class);
    }
}
