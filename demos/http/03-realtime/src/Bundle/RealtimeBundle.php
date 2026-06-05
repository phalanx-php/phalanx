<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Realtime\Bundle;

use Phalanx\Boot\AppContext;
use Phalanx\HttpClient\Client;
use Phalanx\HttpClient\Config;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Http\Sse\SseStreamFactory;

class RealtimeBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        \Phalanx\HttpClient\Client::services(new \Phalanx\HttpClient\Config())->services($services, $context);

        $services->singleton(SseStreamFactory::class)
            ->factory(static fn(): SseStreamFactory => new SseStreamFactory());
    }
}
