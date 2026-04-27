<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final readonly class WsServiceBundle implements ServiceBundle
{
    /** @param list<string> $subprotocols */
    public function __construct(
        private array $subprotocols = [],
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $services->singleton(WsGateway::class)
            ->factory(static fn() => new WsGateway());

        $subprotocols = $this->subprotocols;
        $services->singleton(WsHandshake::class)
            ->factory(static fn() => new WsHandshake($subprotocols));
    }
}
