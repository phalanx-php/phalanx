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
        $services->singleton(WorkerGreetingServiceImpl::class)
            ->factory(static fn(): WorkerGreetingServiceImpl => new WorkerGreetingServiceImpl());
        $services->alias(WorkerGreetingService::class, WorkerGreetingServiceImpl::class);
    }
}
