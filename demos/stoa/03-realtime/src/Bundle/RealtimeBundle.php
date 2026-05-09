<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Bundle;

use Phalanx\Boot\AppContext;
use Phalanx\Iris\Iris;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Stoa\Sse\SseStreamFactory;

class RealtimeBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        Iris::services()->services($services, $context);

        $services->singleton(SseStreamFactory::class)
            ->factory(static fn(): SseStreamFactory => new SseStreamFactory());
    }
}
