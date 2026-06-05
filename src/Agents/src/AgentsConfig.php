<?php

declare(strict_types=1);

namespace Phalanx\Agents;

use Phalanx\Agents\Hook\StepHook;
use Phalanx\Agents\Mcp\McpServer;
use Phalanx\Agents\Router\InvocationRouter;

final class AgentsConfig
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
