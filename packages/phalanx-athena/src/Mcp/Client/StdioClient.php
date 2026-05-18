<?php

declare(strict_types=1);

namespace Phalanx\Athena\Mcp\Client;

use Phalanx\Athena\Mcp\McpClient;
use Phalanx\Athena\Mcp\McpConnection;
use Phalanx\Athena\Mcp\McpServer;
use Phalanx\Athena\Mcp\Transport;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcess;

final class StdioClient implements McpClient
{
    public function connect(TaskScope $scope, McpServer $server): McpConnection
    {
        if ($server->transport !== Transport::Stdio) {
            throw new \RuntimeException(
                "StdioClient requires Transport::Stdio, got {$server->transport->value}",
            );
        }

        if (!($scope instanceof TaskExecutor)) {
            throw new \RuntimeException('StdioClient requires a scope that implements TaskExecutor');
        }

        $argv = self::parseCommand($server->endpoint);
        $process = StreamingProcess::command($argv);

        if ($server->env !== []) {
            $process = $process->withEnv($server->env);
        }

        /** @var TaskScope&TaskExecutor $scope */
        $handle = $process->start($scope);

        return new StdioConnection($handle, $server->name);
    }

    /** @return list<string> */
    private static function parseCommand(string $command): array
    {
        $trimmed = trim($command);

        if ($trimmed === '') {
            throw new \RuntimeException('MCP server endpoint command is empty');
        }

        /** @var list<string> */
        return preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
    }
}
