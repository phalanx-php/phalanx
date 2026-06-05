<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Client\Tests\Unit;

use Phalanx\Application;
use Phalanx\WebSocket\Client;
use Phalanx\WebSocket\Client\Exception;
use Phalanx\WebSocket\Facade;
use Phalanx\WebSocket\Runtime\Identity\WebSocketResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * URL validation and resource-cleanup paths for Client::connect().
 *
 * Live handshake + frame round-trip belongs in a separate integration
 * test that boots a coroutine-native loopback server (mirroring
 * StreamChunkedTest); these cases pin the synchronous failure
 * paths so the resource ledger is never leaked on bad input.
 */
final class ConnectTest extends TestCase
{
    #[Test]
    public function ConnectRejectsMalformedUrl(): void
    {
        Application::starting()
            ->providers(\Phalanx\WebSocket\Facade::services())
            ->run(Task::named(
                'test.websocket.client.bad-url',
                static function (ExecutionScope $scope): void {
                    $client = $scope->service(\Phalanx\WebSocket\Client::class);

                    $beforeLive = $scope->runtime->memory->resources->liveCount(
                        WebSocketResourceSid::WebSocketClientConnection,
                    );

                    try {
                        $client->connect($scope, 'not-a-url');
                        self::fail('Expected WsClientException for malformed URL.');
                    } catch (\Phalanx\WebSocket\Client\Exception $e) {
                        self::assertStringContainsString('Invalid WebSocket URL', $e->getMessage());
                    }

                    $afterLive = $scope->runtime->memory->resources->liveCount(
                        WebSocketResourceSid::WebSocketClientConnection,
                    );

                    self::assertSame(
                        $beforeLive,
                        $afterLive,
                        'Malformed URL must not leak a live managed resource.',
                    );
                },
            ));
    }

    #[Test]
    public function ConnectRejectsUnsupportedScheme(): void
    {
        Application::starting()
            ->providers(\Phalanx\WebSocket\Facade::services())
            ->run(Task::named(
                'test.websocket.client.bad-scheme',
                static function (ExecutionScope $scope): void {
                    $client = $scope->service(\Phalanx\WebSocket\Client::class);

                    try {
                        $client->connect($scope, 'http://example.test/socket');
                        self::fail('Expected WsClientException for non-ws scheme.');
                    } catch (\Phalanx\WebSocket\Client\Exception $e) {
                        self::assertStringContainsString('Unsupported WebSocket scheme', $e->getMessage());
                    }
                },
            ));
    }
}
