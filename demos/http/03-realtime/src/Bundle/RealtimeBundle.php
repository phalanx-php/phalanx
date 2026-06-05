<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Realtime\Bundle;

use Phalanx\Boot\AppContext;
use Phalanx\HttpClient\HttpClient;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Http\Sse\SseStreamFactory;

class RealtimeBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        HttpClient::services()->services($services, $context);

        $services->singleton(SseStreamFactory::class)
            ->factory(static fn(): SseStreamFactory => new SseStreamFactory());
    }
}
