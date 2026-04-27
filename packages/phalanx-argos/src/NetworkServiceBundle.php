<?php

declare(strict_types=1);

namespace Phalanx\Argos;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use React\Datagram\Factory as DatagramFactory;
use React\Socket\Connector;

final class NetworkServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->config(NetworkConfig::class, static fn(array $ctx) => new NetworkConfig(
            defaultTimeout: (float) ($ctx['NETWORK_DEFAULT_TIMEOUT'] ?? 5.0),
            defaultConcurrency: (int) ($ctx['NETWORK_DEFAULT_CONCURRENCY'] ?? 50),
            pingBinary: (string) ($ctx['NETWORK_PING_BINARY'] ?? 'ping'),
            broadcastAddress: (string) ($ctx['NETWORK_BROADCAST_ADDRESS'] ?? '255.255.255.255'),
            wolPort: (int) ($ctx['NETWORK_WOL_PORT'] ?? 9),
        ));

        $services->singleton(DatagramFactory::class)
            ->factory(static fn() => new DatagramFactory());

        $services->singleton(Connector::class)
            ->factory(static fn() => new Connector());
    }
}
