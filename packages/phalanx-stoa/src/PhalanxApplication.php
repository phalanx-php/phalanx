<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\AppHost;
use Phalanx\Hermes\WsRouteGroup;

final readonly class PhalanxApplication
{
    /**
     * @param list<WsRouteGroup> $wsGroups
     */
    public function __construct(
        public AppHost $host,
        public RouteGroup $routes,
        public array $wsGroups = [],
        public ?UdpConfig $udp = null,
    ) {}
}
