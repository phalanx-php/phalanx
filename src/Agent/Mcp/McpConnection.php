<?php

declare(strict_types=1);

namespace Phalanx\Agent\Mcp;

use Phalanx\Agent\Effect\Outcome;
use Phalanx\Scope\TaskScope;

interface McpConnection
{
    /** @return list<McpTool> */
    public function tools(TaskScope $scope): array;

    /** @param array<string, mixed> $args */
    public function invoke(TaskScope $scope, string $toolName, array $args): Outcome;

    /**
     * Whether the underlying transport is still open and able to accept
     * requests. Callers can use this to assert liveness before sending
     * further messages or before a clean disconnect.
     */
    public function isRunning(): bool;

    public function disconnect(TaskScope $scope): void;
}
