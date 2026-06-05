<?php

declare(strict_types=1);

namespace Phalanx\Agents\Mcp;

use Phalanx\Scope\TaskScope;

interface McpClient
{
    public function connect(TaskScope $scope, McpServer $server): McpConnection;
}
