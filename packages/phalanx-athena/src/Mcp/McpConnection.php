<?php

declare(strict_types=1);

namespace Phalanx\Athena\Mcp;

use Phalanx\Athena\Effect\Outcome;
use Phalanx\Scope\TaskScope;

interface McpConnection
{
    /** @return list<McpTool> */
    public function tools(): array;

    /** @param array<string, mixed> $args */
    public function invoke(TaskScope $scope, string $toolName, array $args): Outcome;

    public function disconnect(): void;
}
