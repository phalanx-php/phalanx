<?php

declare(strict_types=1);

namespace Phalanx\Demos\Worker\BasicWorkers;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class WorkerDemoServiceBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(GreetingServiceImpl::class)
            ->factory(static fn(): GreetingServiceImpl => new GreetingServiceImpl());
        $services->alias(GreetingService::class, GreetingServiceImpl::class);
    }
}
