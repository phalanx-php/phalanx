<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit\Mcp;

use Phalanx\Agent\Mcp\Client\StdioClient;
use Phalanx\Agent\Mcp\Client\StdioConnection;
use Phalanx\Agent\Mcp\McpServer;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class StdioClientTest extends PhalanxTestCase
{
    private string $echoServerPath;

    #[Test]
    public function connectAndDiscoverTools(): void
    {
        $path = $this->echoServerPath;

        $tools = $this->scope->run(
            static function (ExecutionScope $scope) use ($path): array {
                $server = McpServer::stdio('echo-server', 'php ' . $path);
                $connection = new StdioClient()->connect($scope, $server);
                $tools = $connection->tools($scope);
                $connection->disconnect($scope);

                return $tools;
            },
            'test.stdio.discover',
        );

        self::assertCount(1, $tools);
        self::assertSame('echo_tool', $tools[0]->name);
        self::assertSame('Echoes input', $tools[0]->description);
        self::assertSame('echo-server', $tools[0]->serverName);
    }

    #[Test]
    public function invokeEchoesArguments(): void
    {
        $path = $this->echoServerPath;

        $data = $this->scope->run(
            static function (ExecutionScope $scope) use ($path): mixed {
                $server = McpServer::stdio('echo-server', 'php ' . $path);
                $connection = new StdioClient()->connect($scope, $server);
                $connection->tools($scope);

                $outcome = $connection->invoke($scope, 'echo_tool', ['message' => 'hello']);
                $connection->disconnect($scope);

                return $outcome->data;
            },
            'test.stdio.invoke',
        );

        self::assertIsArray($data);
        self::assertSame('Echo: hello', $data['content'][0]['text'] ?? '');
    }

    #[Test]
    public function disconnectStopsProcess(): void
    {
        $path = $this->echoServerPath;
        $wasRunning = null;
        $afterDisconnect = null;

        $this->scope->run(
            static function (ExecutionScope $scope) use ($path, &$wasRunning, &$afterDisconnect): void {
                $server = McpServer::stdio('echo-server', 'php ' . $path);
                $connection = new StdioClient()->connect($scope, $server);
                self::assertInstanceOf(StdioConnection::class, $connection);
                $connection->tools($scope);

                $wasRunning = $connection->isRunning();
                $connection->disconnect($scope);
                $afterDisconnect = $connection->isRunning();
            },
            'test.stdio.disconnect',
        );

        self::assertTrue($wasRunning);
        self::assertFalse($afterDisconnect);
    }

    #[Test]
    public function invalidTransportThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('StdioClient requires Transport::Stdio');

        $this->scope->run(
            static function (ExecutionScope $scope): void {
                $server = McpServer::sse('some-server', 'https://example.com/sse');
                new StdioClient()->connect($scope, $server);
            },
            'test.stdio.invalid_transport',
        );
    }

    #[Test]
    public function scopeDisposalKillsProcess(): void
    {
        $path = $this->echoServerPath;
        $connection = null;

        $this->scope->run(
            static function (ExecutionScope $scope) use ($path, &$connection): void {
                $server = McpServer::stdio('echo-server', 'php ' . $path);
                $connection = new StdioClient()->connect($scope, $server);
                self::assertInstanceOf(StdioConnection::class, $connection);
                $connection->tools($scope);
            },
            'test.stdio.scope_dispose',
        );

        self::assertNotNull($connection);
        self::assertFalse($connection->isRunning());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->echoServerPath = dirname(__DIR__, 2) . '/Fixtures/mcp-echo-server.php';
    }
}
