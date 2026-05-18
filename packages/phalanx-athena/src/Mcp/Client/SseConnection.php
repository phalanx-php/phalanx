<?php

declare(strict_types=1);

namespace Phalanx\Athena\Mcp\Client;

use Generator;
use Phalanx\Athena\Effect\Outcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Mcp\JsonRpc\Notification;
use Phalanx\Athena\Mcp\JsonRpc\Request;
use Phalanx\Athena\Mcp\JsonRpc\Response;
use Phalanx\Athena\Mcp\McpConnection;
use Phalanx\Athena\Mcp\McpTool;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpStream;
use Phalanx\Panoply\Effect\Outcome as PanoplyOutcome;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Scope\TaskScope;

final class SseConnection implements McpConnection
{
    private int $nextId = 0;

    /** @param Generator<int, array{event: string, data: string, id: ?string}> $events */
    public function __construct(
        private HttpClient $httpClient,
        private HttpStream $sseStream,
        private Generator $events,
        private(set) string $postUrl,
        private(set) string $serverName,
    ) {
    }

    /** @return list<McpTool> */
    public function tools(TaskScope $scope): array
    {
        $initResponse = $this->sendRequest($scope, new Request($this->nextId(), 'initialize', [
            'protocolVersion' => '2024-11-05',
            'capabilities'    => [],
            'clientInfo'      => ['name' => 'phalanx-athena', 'version' => '0.2'],
        ]));

        if ($initResponse->isError) {
            throw new \RuntimeException('MCP initialize failed: ' . ($initResponse->error['message'] ?? 'unknown'));
        }

        $this->sendNotification($scope, 'notifications/initialized');

        $listResponse = $this->sendRequest($scope, new Request($this->nextId(), 'tools/list'));

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

        $response = $this->sendRequest($scope, new Request($this->nextId(), 'tools/call', [
            'name'      => $toolName,
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

    public function disconnect(TaskScope $scope): void
    {
        $this->sendRequest($scope, new Request($this->nextId(), 'shutdown'));
        $this->sseStream->close();
    }

    private function sendRequest(Scope&Suspendable $scope, Request $request): Response
    {
        $body = $request->encode();

        $this->httpClient->post(
            $scope,
            $this->postUrl,
            $body,
            ['Content-Type' => ['application/json']],
        );

        return $this->readResponse();
    }

    /** @param array<string, mixed> $params */
    private function sendNotification(Scope&Suspendable $scope, string $method, array $params = []): void
    {
        $this->httpClient->post(
            $scope,
            $this->postUrl,
            new Notification($method, $params)->encode(),
            ['Content-Type' => ['application/json']],
        );
    }

    private function readResponse(): Response
    {
        while ($this->events->valid()) {
            $event = $this->events->current();
            $this->events->next();

            if ($event['event'] === 'message') {
                return Response::decode($event['data']);
            }
        }

        throw new \RuntimeException('SSE stream ended before a message response was received');
    }

    private function nextId(): int
    {
        return ++$this->nextId;
    }
}
