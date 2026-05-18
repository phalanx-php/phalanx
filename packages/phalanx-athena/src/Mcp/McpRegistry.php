<?php

declare(strict_types=1);

namespace Phalanx\Athena\Mcp;

use Phalanx\Athena\Effect\Outcome;
use Phalanx\Scope\TaskScope;

final class McpRegistry
{
    /** @var list<McpTool> */
    private(set) array $tools = [];

    /** @var array<string, McpConnection> serverName -> connection */
    private array $index = [];

    /** @param list<McpConnection> $connections */
    public function __construct(array $connections = [])
    {
        foreach ($connections as $connection) {
            $this->register($connection);
        }
    }

    public function register(McpConnection $connection): void
    {
        foreach ($connection->tools() as $tool) {
            $this->tools[] = $tool;
            $this->index[$tool->serverName] = $connection;
        }
    }

    /** @return list<McpTool> */
    public function tools(): array
    {
        return $this->tools;
    }

    public function find(string $toolName): ?McpTool
    {
        return array_find($this->tools, static fn(McpTool $t): bool => $t->name === $toolName);
    }

    public function connection(string $serverName): ?McpConnection
    {
        return $this->index[$serverName] ?? null;
    }

    /** @param array<string, mixed> $args */
    public function invoke(TaskScope $scope, string $toolName, array $args): Outcome
    {
        $tool = $this->find($toolName) ?? throw new \RuntimeException("Unknown MCP tool: {$toolName}");

        return ($this->index[$tool->serverName])->invoke($scope, $toolName, $args);
    }
}
