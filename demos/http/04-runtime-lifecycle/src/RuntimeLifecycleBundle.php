<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Runtime;

use Acme\HttpDemo\Runtime\Support\RuntimeEvents;
use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

class RuntimeLifecycleBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->singleton(RuntimeEvents::class)
            ->factory(static fn(): RuntimeEvents => new RuntimeEvents());
    }
}
