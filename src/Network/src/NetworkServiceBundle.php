<?php

declare(strict_types=1);

namespace Phalanx\Network;

use Phalanx\Boot\AppContext;
use Phalanx\Config\Config;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

/**
 * Network service registration.
 *
 * Network primitives (UDP, TCP, DNS) come from Runtime as stateless
 * scope-bound primitives -- consumers instantiate them inline rather
 * than going through the container, so they're not registered here.
 *
 * NetworkConfig is the only managed service; tasks read it via
 * $scope->service(NetworkConfig::class).
 */
class NetworkServiceBundle extends ServiceBundle
{
    /** @return list<class-string<Config>> */
    #[\Override]
    public static function configs(): array
    {
        return [NetworkConfig::class];
    }

    public function services(Services $services, AppContext $context): void
    {
    }
}
