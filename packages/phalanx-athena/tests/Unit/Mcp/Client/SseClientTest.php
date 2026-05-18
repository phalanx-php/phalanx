<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Mcp\Client;

use Phalanx\Athena\Mcp\Client\SseClient;
use Phalanx\Athena\Mcp\Client\SseConnection;
use Phalanx\Athena\Mcp\McpServer;
use Phalanx\Athena\Tests\Fixtures\FakeHttpClient;
use Phalanx\Athena\Tests\Fixtures\FakeHttpStream;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SseClient and SseConnection using FakeHttpClient /
 * FakeHttpStream fixtures to avoid Aegis runtime dependencies.
 */
final class SseClientTest extends TestCase
{
    #[Test]
    public function invalidTransportThrows(): void
    {
        $stream = new FakeHttpStream('');
        $client = new SseClient(new FakeHttpClient($stream));
        $scope = new ScopeStub();
        $server = McpServer::stdio('bad', 'php server.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SseClient requires Transport::Sse');

        $client->connect($scope, $server);
    }

    #[Test]
    public function connectReadsEndpointEventAndReturnsSseConnection(): void
    {
        $endpointSse = "event: endpoint\ndata: /mcp/post\n\n";
        $stream = new FakeHttpStream($endpointSse);
        $client = new SseClient(new FakeHttpClient($stream));
        $scope = new ScopeStub();

        $connection = $client->connect($scope, McpServer::sse('test-server', 'http://localhost:8080/sse'));

        self::assertInstanceOf(SseConnection::class, $connection);
        $path = parse_url($connection->postUrl, PHP_URL_PATH);
        self::assertSame('/mcp/post', $path);
        self::assertSame('test-server', $connection->serverName);
    }

    #[Test]
    public function connectRegistersStreamCloseOnScopeDispose(): void
    {
        $endpointSse = "event: endpoint\ndata: /post\n\n";
        $stream = new FakeHttpStream($endpointSse);
        $client = new SseClient(new FakeHttpClient($stream));
        $scope = new ScopeStub();

        $client->connect($scope, McpServer::sse('srv', 'http://localhost/sse'));
        $scope->dispose();

        self::assertTrue($stream->closeCalled);
    }

    #[Test]
    public function non200StatusThrows(): void
    {
        $stream = new FakeHttpStream('', 503);
        $client = new SseClient(new FakeHttpClient($stream));
        $scope = new ScopeStub();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 503');

        $client->connect($scope, McpServer::sse('srv', 'http://localhost/sse'));
    }

    #[Test]
    public function toolsPostsInitializeAndReturnsToolList(): void
    {
        $initJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => ['protocolVersion' => '2024-11-05', 'capabilities' => []],
        ], JSON_THROW_ON_ERROR);

        $listJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'result'  => [
                'tools' => [
                    ['name' => 'zeus_strike', 'description' => 'Hurls a thunderbolt',
                        'inputSchema' => ['type' => 'object']],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $endpointSse = "event: endpoint\ndata: /post\n\n";
        $messageSse =
            "event: message\ndata: {$initJson}\n\n" .
            "event: message\ndata: {$listJson}\n\n";

        [$connection, $scope] = $this->buildConnectedPair($endpointSse . $messageSse);

        $tools = $connection->tools($scope);

        self::assertCount(1, $tools);
        self::assertSame('zeus_strike', $tools[0]->name);
        self::assertSame('Hurls a thunderbolt', $tools[0]->description);
        self::assertSame('test-server', $tools[0]->serverName);
    }

    #[Test]
    public function invokePostsToolsCallAndReturnsOutcome(): void
    {
        $initJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => ['protocolVersion' => '2024-11-05', 'capabilities' => []],
        ], JSON_THROW_ON_ERROR);

        $listJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'result'  => [
                'tools' => [
                    ['name' => 'echo', 'description' => 'Echo', 'inputSchema' => ['type' => 'object']],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $invokeJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 3,
            'result'  => ['content' => [['type' => 'text', 'text' => 'pong']]],
        ], JSON_THROW_ON_ERROR);

        $endpointSse = "event: endpoint\ndata: /post\n\n";
        $messageSse =
            "event: message\ndata: {$initJson}\n\n" .
            "event: message\ndata: {$listJson}\n\n" .
            "event: message\ndata: {$invokeJson}\n\n";

        [$connection, $scope] = $this->buildConnectedPair($endpointSse . $messageSse);

        $connection->tools($scope);
        $outcome = $connection->invoke($scope, 'echo', ['msg' => 'ping']);

        self::assertNull($outcome->error);
        self::assertIsArray($outcome->data);
        self::assertSame('pong', $outcome->data['content'][0]['text'] ?? '');
    }

    #[Test]
    public function disconnectClosesStream(): void
    {
        $shutdownJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => null,
        ], JSON_THROW_ON_ERROR);

        $endpointSse = "event: endpoint\ndata: /post\n\n";
        $messageSse = "event: message\ndata: {$shutdownJson}\n\n";

        $sseBody = $endpointSse . $messageSse;
        $stream = new FakeHttpStream($sseBody);
        $httpClient = new FakeHttpClient($stream);
        $scope = new ScopeStub();

        $connection = new SseClient($httpClient)
            ->connect($scope, McpServer::sse('test-server', 'http://localhost/sse'));
        self::assertInstanceOf(SseConnection::class, $connection);

        $connection->disconnect($scope);

        self::assertTrue($stream->closeCalled);
    }

    #[Test]
    public function endpointEventWithAbsoluteUrlPreserved(): void
    {
        $endpointSse = "event: endpoint\ndata: https://example.com/mcp/post\n\n";
        $stream = new FakeHttpStream($endpointSse);
        $client = new SseClient(new FakeHttpClient($stream));
        $scope = new ScopeStub();

        $connection = $client->connect($scope, McpServer::sse('srv', 'http://localhost/sse'));

        self::assertInstanceOf(SseConnection::class, $connection);
        self::assertSame('https://example.com/mcp/post', $connection->postUrl);
    }

    #[Test]
    public function noEndpointEventThrows(): void
    {
        $messageSse = "event: message\ndata: {\"hello\":true}\n\n";
        $stream = new FakeHttpStream($messageSse);
        $client = new SseClient(new FakeHttpClient($stream));
        $scope = new ScopeStub();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not send an endpoint event');

        $client->connect($scope, McpServer::sse('srv', 'http://localhost/sse'));
    }

    #[Test]
    public function initializeErrorThrows(): void
    {
        $errorJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'error'   => ['code' => -32600, 'message' => 'bad init'],
        ], JSON_THROW_ON_ERROR);

        $sseBody = "event: endpoint\ndata: /post\n\nevent: message\ndata: {$errorJson}\n\n";
        [$connection, $scope] = $this->buildConnectedPair($sseBody);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP initialize failed: bad init');

        $connection->tools($scope);
    }

    #[Test]
    public function toolsListErrorThrows(): void
    {
        $initJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => ['protocolVersion' => '2024-11-05', 'capabilities' => []],
        ], JSON_THROW_ON_ERROR);

        $errorJson = json_encode([
            'jsonrpc' => '2.0',
            'id'      => 2,
            'error'   => ['code' => -32601, 'message' => 'tools/list not supported'],
        ], JSON_THROW_ON_ERROR);

        $sseBody = "event: endpoint\ndata: /post\n\n" .
            "event: message\ndata: {$initJson}\n\n" .
            "event: message\ndata: {$errorJson}\n\n";
        [$connection, $scope] = $this->buildConnectedPair($sseBody);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MCP tools/list failed');

        $connection->tools($scope);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0: SseConnection, 1: ScopeStub} */
    private function buildConnectedPair(string $sseBytes): array
    {
        $stream = new FakeHttpStream($sseBytes);
        $httpClient = new FakeHttpClient($stream);
        $scope = new ScopeStub();
        $server = McpServer::sse('test-server', 'http://localhost/sse');

        $connection = new SseClient($httpClient)->connect($scope, $server);
        self::assertInstanceOf(SseConnection::class, $connection);

        return [$connection, $scope];
    }
}
