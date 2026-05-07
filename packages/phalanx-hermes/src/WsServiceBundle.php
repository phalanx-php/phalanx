<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final readonly class WsServiceBundle implements ServiceBundle
{
    /** @param list<string> $subprotocols */
    public function __construct(
        private array $subprotocols = [],
        private ?WsClientConfig $clientConfig = null,
    ) {}

    public function services(Services $services, array $context): void
    {
        $subprotocols = $this->subprotocols;
        $clientConfig = $this->clientConfig;

        $services->singleton(WsGateway::class)->factory(static fn() => new WsGateway());

        $services
            ->singleton(WsHandshake::class)
            ->factory(static fn() => new WsHandshake($subprotocols));

        $services->config(
            WsClientConfig::class,
            static fn(): WsClientConfig => $clientConfig ?? WsClientConfig::default(),
        );
    }
}
