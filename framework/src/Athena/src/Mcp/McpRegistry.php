<?php

declare(strict_types=1);

namespace Phalanx\Athena\Mcp;

use Phalanx\Athena\Effect\Outcome;
use Phalanx\Scope\TaskScope;

final class McpRegistry
{
    /** @var list<McpTool> */
    private array $tools = [];

    /** @var array<string, McpTool> toolName -> tool */
    private array $nameIndex = [];

    /** @var array<string, McpConnection> serverName -> connection */
    private array $serverIndex = [];

    /** @param list<McpServer> $pendingServers */
    public function __construct(private array $pendingServers = [])
    {
    }

    /** @param array<string, mixed> $args */
    public function invoke(TaskScope $scope, string $toolName, array $args): Outcome
    {
        $tool = $this->find($toolName) ?? throw new \RuntimeException("Unknown MCP tool: {$toolName}");

        return ($this->serverIndex[$tool->serverName])->invoke($scope, $toolName, $args);
    }

    public function register(TaskScope $scope, McpConnection $connection): void
    {
        foreach ($connection->tools($scope) as $tool) {
            $this->tools[] = $tool;
            $this->nameIndex[$tool->name] = $tool;
            $this->serverIndex[$tool->serverName] = $connection;
        }
    }

    /** @return list<McpServer> */
    public function pendingServers(): array
    {
        return $this->pendingServers;
    }

    /** @return list<McpTool> */
    public function tools(): array
    {
        return $this->tools;
    }

    public function find(string $toolName): ?McpTool
    {
        return $this->nameIndex[$toolName] ?? null;
    }

    public function connection(string $serverName): ?McpConnection
    {
        return $this->serverIndex[$serverName] ?? null;
    }
}
