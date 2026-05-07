<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Bundle;

use Phalanx\Iris\Iris;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Stoa\Sse\SseStreamFactory;

final class RealtimeBundle implements ServiceBundle
{
    /** @param array<string, mixed> $context */
    public function services(Services $services, array $context): void
    {
        Iris::services()->services($services, $context);

        $services->singleton(SseStreamFactory::class)
            ->factory(static fn(): SseStreamFactory => new SseStreamFactory());
    }
}
