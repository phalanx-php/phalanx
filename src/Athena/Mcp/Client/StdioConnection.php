<?php

declare(strict_types=1);

namespace Phalanx\Athena\Mcp\Client;

use Phalanx\Athena\Effect\Outcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Mcp\JsonRpc\Notification;
use Phalanx\Athena\Mcp\JsonRpc\Request;
use Phalanx\Athena\Mcp\JsonRpc\Response;
use Phalanx\Athena\Mcp\McpConnection;
use Phalanx\Athena\Mcp\McpTool;
use Phalanx\Panoply\Effect\Outcome as PanoplyOutcome;
use Phalanx\Scope\TaskScope;
use Phalanx\System\StreamingProcessHandle;

final class StdioConnection implements McpConnection
{
    private int $nextId = 0;

    public function __construct(
        private StreamingProcessHandle $handle,
        private string $serverName,
    ) {
    }

    /** @return list<McpTool> */
    public function tools(TaskScope $scope): array
    {
        $initResponse = $this->sendRequest(new Request($this->nextId(), 'initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [],
            'clientInfo' => ['name' => 'phalanx-athena', 'version' => '0.2'],
        ]));

        if ($initResponse->isError) {
            throw new \RuntimeException('MCP initialize failed: ' . ($initResponse->error['message'] ?? 'unknown'));
        }

        $this->sendNotification('notifications/initialized');

        $listResponse = $this->sendRequest(new Request($this->nextId(), 'tools/list'));

        if ($listResponse->isError) {
            throw new \RuntimeException('MCP tools/list failed: ' . ($listResponse->error['message'] ?? 'unknown'));
        }

        /** @var array{tools: list<array{name: string, description: string, inputSchema: array<string, mixed>}>} $result */
        $result = $listResponse->result;

        $serverName = $this->serverName;

        return array_map(
            static fn(array $tool): McpTool => new McpTool(
                $tool['name'],
                $tool['description'],
                $tool['inputSchema'],
                $serverName,
            ),
            $result['tools'] ?? [],
        );
    }

    /** @param array<string, mixed> $args */
    public function invoke(TaskScope $scope, string $toolName, array $args): Outcome
    {
        $start = (int) round(microtime(true) * 1000);

        $response = $this->sendRequest(new Request($this->nextId(), 'tools/call', [
            'name' => $toolName,
            'arguments' => $args,
        ]));

        $elapsed = (int) round(microtime(true) * 1000) - $start;

        if ($response->isError) {
            $message = $response->error['message'] ?? 'MCP error';

            return Outcome::failed(
                Resolution::McpTool,
                new \RuntimeException($message),
                PanoplyOutcome::failed('McpError', $message, $elapsed),
            );
        }

        return Outcome::routed(
            Resolution::McpTool,
            PanoplyOutcome::succeeded(null, $elapsed),
            $response->result,
        );
    }

    public function isRunning(): bool
    {
        return $this->handle->isRunning();
    }

    public function disconnect(TaskScope $scope): void
    {
        $this->sendRequest(new Request($this->nextId(), 'shutdown'));
        $this->handle->stop();
    }

    private function sendRequest(Request $request): Response
    {
        $this->handle->write($request->encode());
        $line = $this->handle->readLine();

        return Response::decode($line);
    }

    /** @param array<string, mixed> $params */
    private function sendNotification(string $method, array $params = []): void
    {
        $this->handle->write(new Notification($method, $params)->encode());
    }

    private function nextId(): int
    {
        return ++$this->nextId;
    }
}
