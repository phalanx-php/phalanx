<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Integration;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\WebSocket\Client;
use Phalanx\WebSocket\Tests\Support\Rfc6455TestServer;
use Phalanx\WebSocket\Facade;
use Phalanx\WebSocket\CloseCode;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine\Socket;

/**
 * End-to-end proof for the Swoole-native WebSocket client handshake.
 *
 * Uses a strict RFC6455 peer fixture so the test stays focused on the client:
 * connect, surface the first text frame, and close without leaking the peer.
 */
final class WsClientHandshakeTest extends PhalanxTestCase
{
    #[Test]
    public function handshakeYieldsFirstFrameAndClosesCleanly(): void
    {
        $testApp = $this->testApp([], \Phalanx\WebSocket\Facade::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $testApp->application->startup();
            $scope = $testApp->application->createScope();

            try {
                $server = Rfc6455TestServer::start($scope, static function (
                    Socket $conn,
                    ExecutionScope $serverScope,
                ): void {
                    Rfc6455TestServer::sendText($conn, 'phalanx-ws');
                    Rfc6455TestServer::drainUntilClosed($conn, $serverScope, 30.0);
                });

                $client = $scope->service(\Phalanx\WebSocket\Client::class);
                $connection = $client->connect($scope, $server->url('/echo'));

                try {
                    $first = null;
                    foreach ($connection->messages() as $msg) {
                        $first = $msg;
                        break;
                    }

                    self::assertNotNull($first, 'expected at least one inbound frame');
                    self::assertTrue($first->isText, 'expected text opcode');
                    self::assertSame('phalanx-ws', $first->payload);
                } finally {
                    $connection->close(\Phalanx\WebSocket\CloseCode::Normal, 'test_done');
                    self::assertTrue($server->awaitDone(), 'server fixture did not signal done within 2s');
                }
            } finally {
                $scope->dispose();
            }
        });
    }
}
