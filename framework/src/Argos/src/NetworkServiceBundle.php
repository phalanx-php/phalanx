<?php

declare(strict_types=1);

namespace Phalanx\Argos;

use Phalanx\Boot\AppContext;
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
class NetworkServiceBundle extends ServiceBundle
{
    public function services(Services $services, AppContext $context): void
    {
        $services->config(NetworkConfig::class, static fn(AppContext $ctx): NetworkConfig => new NetworkConfig(
            defaultTimeout: $ctx->float('NETWORK_DEFAULT_TIMEOUT', 5.0),
            defaultConcurrency: $ctx->int('NETWORK_DEFAULT_CONCURRENCY', 50),
            pingBinary: $ctx->string('NETWORK_PING_BINARY', 'ping'),
            broadcastAddress: $ctx->string('NETWORK_BROADCAST_ADDRESS', '255.255.255.255'),
            wolPort: $ctx->int('NETWORK_WOL_PORT', 9),
        ));
    }
}
