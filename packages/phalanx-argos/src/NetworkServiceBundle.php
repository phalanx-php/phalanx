<?php

declare(strict_types=1);

namespace Phalanx\Argos;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Argos service registration.
 *
 * Network primitives (UDP, TCP, DNS) come from Aegis as stateless
 * scope-bound primitives -- consumers instantiate them inline rather
 * than going through the container, so they're not registered here.
 *
 * NetworkConfig is the only managed service; tasks read it via
 * $scope->service(NetworkConfig::class).
 */
final class NetworkServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->config(NetworkConfig::class, static fn(array $ctx): NetworkConfig => new NetworkConfig(
            defaultTimeout: (float) ($ctx['NETWORK_DEFAULT_TIMEOUT'] ?? 5.0),
            defaultConcurrency: (int) ($ctx['NETWORK_DEFAULT_CONCURRENCY'] ?? 50),
            pingBinary: (string) ($ctx['NETWORK_PING_BINARY'] ?? 'ping'),
            broadcastAddress: (string) ($ctx['NETWORK_BROADCAST_ADDRESS'] ?? '255.255.255.255'),
            wolPort: (int) ($ctx['NETWORK_WOL_PORT'] ?? 9),
        ));
    }
}
