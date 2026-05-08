<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final readonly class WsServiceBundle implements ServiceBundle
{
    public function __construct(
        private ?WsClientConfig $clientConfig = null,
    ) {
    }

    public function services(Services $services, array $context): void
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
