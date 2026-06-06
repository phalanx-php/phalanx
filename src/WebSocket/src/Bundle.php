<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class Bundle extends ServiceBundle
{
    public function __construct(
        private readonly ?\Phalanx\WebSocket\Client\Config $clientConfig = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $clientConfig = $this->clientConfig;

        if (!$services->has(\Phalanx\WebSocket\Gateway::class)) {
            $services->singleton(\Phalanx\WebSocket\Gateway::class)->factory(static fn() => new \Phalanx\WebSocket\Gateway());
        }

        if (!$services->has(\Phalanx\WebSocket\Client\Config::class)) {
            $services->singleton(\Phalanx\WebSocket\Client\Config::class)
                ->factory(static fn(): \Phalanx\WebSocket\Client\Config => $clientConfig ?? \Phalanx\WebSocket\Client\Config::default());
        }

        if (!$services->has(\Phalanx\WebSocket\Client::class)) {
            $services
                ->singleton(\Phalanx\WebSocket\Client::class)
                ->needs(\Phalanx\WebSocket\Client\Config::class)
                ->factory(static fn(\Phalanx\WebSocket\Client\Config $config): \Phalanx\WebSocket\Client => new \Phalanx\WebSocket\Client($config));
        }
    }
}
