<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Client\Tests\Unit;

use Phalanx\WebSocket\Client;
use Phalanx\WebSocket\Client\Exception;
use Phalanx\WebSocket\WebSocket;
use Phalanx\WebSocket\Runtime\Identity\WebSocketResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * URL validation and resource-cleanup paths for Client::connect().
 *
 * Live handshake + frame round-trip belongs in a separate integration
 * test that boots a coroutine-native loopback server (mirroring
 * StreamChunkedTest); these cases pin the synchronous failure
 * paths so the resource ledger is never leaked on bad input.
 */
final class ConnectTest extends PhalanxTestCase
{
    #[Test]
    public function connectRejectsMalformedUrl(): void
    {
        $this->testApp(bundles: WebSocket::services())
            ->scoped(Task::named(
                'test.websocket.client.bad-url',
                static function (ExecutionScope $scope): void {
                    $client = $scope->service(Client::class);

                    $beforeLive = $scope->runtime->memory->resources->liveCount(
                        WebSocketResourceSid::WebSocketClientConnection,
                    );

                    try {
                        $client->connect($scope, 'not-a-url');
                        self::fail('Expected WsClientException for malformed URL.');
                    } catch (Exception $e) {
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
    public function connectRejectsUnsupportedScheme(): void
    {
        $this->testApp(bundles: WebSocket::services())
            ->scoped(Task::named(
                'test.websocket.client.bad-scheme',
                static function (ExecutionScope $scope): void {
                    $client = $scope->service(Client::class);

                    try {
                        $client->connect($scope, 'http://example.test/socket');
                        self::fail('Expected WsClientException for non-ws scheme.');
                    } catch (Exception $e) {
                        self::assertStringContainsString('Unsupported WebSocket scheme', $e->getMessage());
                    }
                },
            ));
    }
}
