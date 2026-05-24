<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Optional;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class WsServiceBundle extends ServiceBundle
{
    /**
     * Hermes' WebSocket surface is feature-flagged; absence of host/port
     * env keys must not block boot. Both entries warn on missing rather
     * than failing.
     */
    #[\Override]
    public static function harness(): BootHarness
    {
        return BootHarness::of(
            Optional::env('PHALANX_WS_HOST', fallback: '0.0.0.0', description: 'Hermes WebSocket bind host'),
            Optional::env('PHALANX_WS_PORT', fallback: '8081', description: 'Hermes WebSocket bind port'),
        );
    }

    public function __construct(
        private ?WsClientConfig $clientConfig = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $clientConfig = $this->clientConfig;

        if (!$services->has(WsGateway::class)) {
            $services->singleton(WsGateway::class)->factory(static fn() => new WsGateway());
        }

        if (!$services->has(WsClientConfig::class)) {
            $services->config(
                WsClientConfig::class,
                static fn(): WsClientConfig => $clientConfig ?? WsClientConfig::default(),
            );
        }

        if (!$services->has(WsClient::class)) {
            $services
                ->singleton(WsClient::class)
                ->needs(WsClientConfig::class)
                ->factory(static fn(WsClientConfig $config): WsClient => new WsClient($config));
        }
    }
}
