<?php

declare(strict_types=1);

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\WebSocket\WsGateway;

final class DashboardBundle implements ServiceBundle
{
    public function __construct(private readonly WsGateway $gateway) {}

    public function services(Services $services, array $context): void
    {
        $gateway = $this->gateway;

        $services->singleton(WsGateway::class)
            ->factory(static fn() => $gateway);

        $services->singleton(DumpStore::class)
            ->factory(static fn() => new DumpStore());
    }
}
