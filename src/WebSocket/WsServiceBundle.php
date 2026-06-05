<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Optional;
use Phalanx\WebSocket\Client\WsClient;
use Phalanx\WebSocket\Client\WsClientConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class WsServiceBundle extends ServiceBundle
{
    public function __construct(
        private ?WsClientConfig $clientConfig = null,
    ) {
    }

    /**
     * WebSocket' WebSocket surface is feature-flagged; absence of host/port
     * env keys must not block boot. Both entries warn on missing rather
     * than failing.
     */
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(
            Optional::env('PHALANX_WS_HOST', fallback: '0.0.0.0', description: 'WebSocket WebSocket bind host'),
            Optional::env('PHALANX_WS_PORT', fallback: '8081', description: 'WebSocket WebSocket bind port'),
        );
    }

    public function services(Services $services, AppContext $context): void
    {
        $clientConfig = $this->clientConfig;

        if (!$services->has(WsGateway::class)) {
            $services->singleton(WsGateway::class)->factory(static fn() => new WsGateway());
        }

        if (!$services->has(WsClientConfig::class)) {
            $services->singleton(WsClientConfig::class)
                ->factory(static fn(): WsClientConfig => $clientConfig ?? WsClientConfig::default());
        }

        if (!$services->has(WsClient::class)) {
            $services
                ->singleton(WsClient::class)
                ->needs(WsClientConfig::class)
                ->factory(static fn(WsClientConfig $config): WsClient => new WsClient($config));
        }
    }
}
