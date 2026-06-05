<?php

declare(strict_types=1);

namespace Phalanx\Agent;

use Phalanx\Agent\Hook\StepHook;
use Phalanx\Agent\Mcp\McpServer;
use Phalanx\Agent\Router\InvocationRouter;

final class AgentConfig
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
