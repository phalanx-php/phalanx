<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\AppHost;

final readonly class PhalanxApplication
{
    public function __construct(
        public AppHost $host,
        public RouteGroup $routes,
        private ?StoaServerConfig $serverConfig = null,
    ) {
    }

    public function serverConfig(?StoaServerConfig $fallback = null): StoaServerConfig
    {
        return $this->serverConfig ?? $fallback ?? StoaServerConfig::defaults();
    }
}
