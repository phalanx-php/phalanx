<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Upgrade;

use OpenSwoole\Http\Response;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Stoa\StoaRequestResource;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Implemented by packages that consume an HTTP/1.1 Upgrade exchange.
 *
 * Stoa owns only the seam: when an Upgrade: header arrives it asks the
 * registered upgradeable to take ownership. The implementation is
 * responsible for validating the request, sending the 101 Switching
 * Protocols handshake, and atomically retyping the Aegis managed
 * resource via {@see ManagedResourceRegistry::upgrade()}.
 *
 * Hermes provides the WebSocket implementation; other protocols can
 * register their own upgradeables through the {@see UpgradeRegistry}.
 */
interface HttpUpgradeable
{
    /**
     * Take ownership of the upgrading connection.
     *
     * Returning the new resource handle records the protocol-bound
     * lifecycle that replaces the original HTTP request resource.
     */
    public function upgrade(
        ServerRequestInterface $request,
        Response $target,
        StoaRequestResource $requestResource,
    ): ManagedResourceHandle;
}
