<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Integration;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\WebSocket\Client;
use Phalanx\WebSocket\Runtime\Identity\WebSocketResourceSid;
use Phalanx\WebSocket\Tests\Support\Rfc6455TestServer;
use Phalanx\WebSocket\WebSocket;
use Phalanx\WebSocket\CloseCode;
use PHPUnit\Framework\Attributes\Test;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

/**
 * Mechanism proofs for the Client cancellation surface.
 *
 * Three orthogonal cases share one Swoole-native server fixture:
 *
 *  1. parent scope dispose cascades close()  -> resource ends Closed, no leak
 *  2. double-close from two coroutines       -> idempotent, single transition
 *  3. abrupt server hangup mid-recv          -> reader exits, close drains
 */
final class ClientCancellationTest extends PhalanxTestCase
{
    #[Test]
    public function scopeDisposeCascadesCloseAndReleasesResource(): void
    {
        $testApp = $this->testApp([], \Phalanx\WebSocket\WebSocket::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $testApp->application->startup();
            $scope = $testApp->application->createScope();

            try {
                $server = self::bootServer($scope, holdSeconds: 1.0);

                $resources = $scope->runtime->memory->resources;
                self::assertSame(
                    0,
                    $resources->liveCount(WebSocketResourceSid::WebSocketClientConnection),
                    'no live ws-client resources before connect',
                );

                $childDone = new Channel(1);
                $scope->go(static function (ExecutionScope $childScope) use ($scope, $server, $childDone): void {
                    // Register the signal FIRST so onDispose runs it LAST (LIFO);
                    // this depends on Client::connect() registering its
                    // handle->close() cleanup via $scope->onDispose(...) — if that
                    // mechanism ever changes, this ordering assumption rots silently
                    // and the parent's liveCount assertion becomes a flake.
                    $childScope->onDispose(static function () use ($childDone): void {
                        $childDone->push(true);
                    });

                    $client = $scope->service(\Phalanx\WebSocket\Client::class);
                    $connection = $client->connect(
                        $childScope,
                        $server->url('/echo'),
                    );

                    foreach ($connection->messages() as $msg) {
                        self::assertSame('hold-open', $msg->payload);
                        break;
                    }
                });

                self::assertTrue($childDone->pop(3), 'child go() did not signal done within 3s');

                self::assertSame(
                    0,
                    $resources->liveCount(WebSocketResourceSid::WebSocketClientConnection),
                    'child-scope dispose must release the ws-client resource',
                );

                self::assertTrue($server->awaitDone(), 'server fixture did not signal done within 2s');
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function doubleCloseFromTwoCoroutinesIsIdempotent(): void
    {
        $testApp = $this->testApp([], \Phalanx\WebSocket\WebSocket::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $testApp->application->startup();
            $scope = $testApp->application->createScope();

            try {
                $server = self::bootServer($scope, holdSeconds: 1.0);

                $client = $scope->service(\Phalanx\WebSocket\Client::class);
                $connection = $client->connect(
                    $scope,
                    $server->url('/echo'),
                );

                foreach ($connection->messages() as $msg) {
                    self::assertSame('hold-open', $msg->payload);
                    break;
                }

                $barrier = new Channel(2);
                $scope->go(static function () use ($connection, $barrier): void {
                    try {
                        $connection->close(\Phalanx\WebSocket\CloseCode::Normal, 'double_a');
                    } finally {
                        $barrier->push(true);
                    }
                });
                $scope->go(static function () use ($connection, $barrier): void {
                    try {
                        $connection->close(\Phalanx\WebSocket\CloseCode::Normal, 'double_b');
                    } finally {
                        $barrier->push(true);
                    }
                });
                self::assertTrue($barrier->pop(2), 'first close coroutine did not finish');
                self::assertTrue($barrier->pop(2), 'second close coroutine did not finish');

                self::assertTrue($connection->closed, 'connection must be closed after double-close');
                self::assertFalse($connection->isConnected, 'isConnected must be false post-close');

                self::assertTrue($server->awaitDone(), 'server fixture did not signal done within 2s');
            } finally {
                $scope->dispose();
            }
        });
    }

    #[Test]
    public function abruptServerHangupTerminatesReaderCleanly(): void
    {
        $testApp = $this->testApp([], \Phalanx\WebSocket\WebSocket::services());

        $this->scope->run(static function (ExecutionScope $_scope) use ($testApp): void {
            $testApp->application->startup();
            $scope = $testApp->application->createScope();

            try {
                // hangup fixture: 101 + one frame, then immediate close from server side.
                $server = self::bootServer($scope, holdSeconds: 0.0);

                $client = $scope->service(\Phalanx\WebSocket\Client::class);
                $connection = $client->connect(
                    $scope,
                    $server->url('/hangup'),
                );

                $payloads = [];
                foreach ($connection->messages() as $msg) {
                    if (!$msg->isClose) {
                        $payloads[] = $msg->payload;
                    }
                }

                self::assertContains('hold-open', $payloads, 'reader should surface the first frame before EOF');

                // the inbound iterator drained; reader has exited. close() must be
                // safe to call even though the underlying socket is already gone.
                $connection->close(\Phalanx\WebSocket\CloseCode::Normal, 'after_eof');
                self::assertTrue($connection->closed);

                self::assertSame(
                    0,
                    $scope->runtime->memory->resources->liveCount(
                        WebSocketResourceSid::WebSocketClientConnection,
                    ),
                    'no leaked ws-client resources after server hangup',
                );

                self::assertTrue($server->awaitDone(), 'server fixture did not signal done within 2s');
            } finally {
                $scope->dispose();
            }
        });
    }

    private static function bootServer(ExecutionScope $scope, float $holdSeconds): Rfc6455TestServer
    {
        return Rfc6455TestServer::start(
            $scope,
            static function (Socket $conn, ExecutionScope $serverScope) use ($holdSeconds): void {
                Rfc6455TestServer::sendText($conn, 'hold-open');

                if ($holdSeconds > 0.0) {
                    Rfc6455TestServer::drainUntilClosed($conn, $serverScope, $holdSeconds);
                }
            },
        );
    }
}
