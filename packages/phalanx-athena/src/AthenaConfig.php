<?php

declare(strict_types=1);

namespace Phalanx\Athena;

use Phalanx\Athena\Hook\StepHook;
use Phalanx\Athena\Mcp\McpServer;
use Phalanx\Athena\Router\InvocationRouter;

final class AthenaConfig
{
    /**
     * @param list<StepHook> $hooks
     * @param list<McpServer> $mcpServers
     */
    public function __construct(
        private(set) InvocationRouter $router,
        private(set) array $hooks = [],
        private(set) array $mcpServers = [],
    ) {
    }
}
