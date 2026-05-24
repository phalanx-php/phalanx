<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Integration;

use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Socket;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server as WebSocketServer;
use Phalanx\Application;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Hermes;
use Phalanx\Hermes\Runtime\Identity\HermesResourceSid;
use Phalanx\Hermes\WsCloseCode;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Mechanism proofs for the WsClient cancellation surface.
 *
 * Three orthogonal cases share one OpenSwoole-native server fixture:
 *
 *  1. parent scope dispose cascades close()  -> resource ends Closed, no leak
 *  2. double-close from two coroutines       -> idempotent, single transition
 *  3. abrupt server hangup mid-recv          -> reader exits, close drains
 */
final class WsClientCancellationTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    #[Test]
    public function scopeDisposeCascadesCloseAndReleasesResource(): void
    {
        Application::starting()
            ->providers(Hermes::services())
            ->run(Task::named(
                'test.hermes.client.cancel.dispose',
                static function (ExecutionScope $scope): void {
                    [$listener, $port, $serverDone] = self::bootListener($scope, holdSeconds: 1.0);

                    $resources = $scope->runtime->memory->resources;
                    self::assertSame(
                        0,
                        $resources->liveCount(HermesResourceSid::WebSocketClientConnection),
                        'no live ws-client resources before connect',
                    );

                    $childDone = new Channel(1);
                    $scope->go(static function (ExecutionScope $childScope) use ($port, $childDone): void {
                        // Register the signal FIRST so onDispose runs it LAST (LIFO);
                        // this depends on WsClient::connect() registering its
                        // handle->close() cleanup via $scope->onDispose(...) — if that
                        // mechanism ever changes, this ordering assumption rots silently
                        // and the parent's liveCount assertion becomes a flake.
                        $childScope->onDispose(static function () use ($childDone): void {
                            $childDone->push(true);
                        });

                        $client = $childScope->service(WsClient::class);
                        $connection = $client->connect(
                            $childScope,
                            'ws://' . self::HOST . ':' . $port . '/echo',
                        );

                        foreach ($connection->messages() as $msg) {
                            self::assertSame('hold-open', $msg->payload);
                            break;
                        }
                    });

                    self::assertTrue($childDone->pop(3), 'child go() did not signal done within 3s');

                    self::assertSame(
                        0,
                        $resources->liveCount(HermesResourceSid::WebSocketClientConnection),
                        'child-scope dispose must release the ws-client resource',
                    );

                    self::assertTrue($serverDone->pop(2), 'server fixture did not signal done within 2s');
                    $listener->close();
                },
            ));
    }

    #[Test]
    public function doubleCloseFromTwoCoroutinesIsIdempotent(): void
    {
        Application::starting()
            ->providers(Hermes::services())
            ->run(Task::named(
                'test.hermes.client.cancel.double-close',
                static function (ExecutionScope $scope): void {
                    [$listener, $port, $serverDone] = self::bootListener($scope, holdSeconds: 1.0);

                    $client = $scope->service(WsClient::class);
                    $connection = $client->connect(
                        $scope,
                        'ws://' . self::HOST . ':' . $port . '/echo',
                    );

                    foreach ($connection->messages() as $msg) {
                        self::assertSame('hold-open', $msg->payload);
                        break;
                    }

                    $barrier = new Channel(2);
                    $scope->go(static function () use ($connection, $barrier): void {
                        try {
                            $connection->close(WsCloseCode::Normal, 'double_a');
                        } finally {
                            $barrier->push(true);
                        }
                    });
                    $scope->go(static function () use ($connection, $barrier): void {
                        try {
                            $connection->close(WsCloseCode::Normal, 'double_b');
                        } finally {
                            $barrier->push(true);
                        }
                    });
                    self::assertTrue($barrier->pop(2), 'first close coroutine did not finish');
                    self::assertTrue($barrier->pop(2), 'second close coroutine did not finish');

                    self::assertTrue($connection->closed, 'connection must be closed after double-close');
                    self::assertFalse($connection->isConnected, 'isConnected must be false post-close');

                    self::assertTrue($serverDone->pop(2), 'server fixture did not signal done within 2s');
                    $listener->close();
                },
            ));
    }

    #[Test]
    public function abruptServerHangupTerminatesReaderCleanly(): void
    {
        Application::starting()
            ->providers(Hermes::services())
            ->run(Task::named(
                'test.hermes.client.cancel.hangup',
                static function (ExecutionScope $scope): void {
                    // hangup fixture: 101 + one frame, then immediate close from server side.
                    [$listener, $port, $serverDone] = self::bootListener($scope, holdSeconds: 0.0);

                    $client = $scope->service(WsClient::class);
                    $connection = $client->connect(
                        $scope,
                        'ws://' . self::HOST . ':' . $port . '/hangup',
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
                    $connection->close(WsCloseCode::Normal, 'after_eof');
                    self::assertTrue($connection->closed);

                    self::assertSame(
                        0,
                        $scope->runtime->memory->resources->liveCount(
                            HermesResourceSid::WebSocketClientConnection,
                        ),
                        'no leaked ws-client resources after server hangup',
                    );

                    self::assertTrue($serverDone->pop(2), 'server fixture did not signal done within 2s');
                    $listener->close();
                },
            ));
    }

    /**
     * @return array{0: Socket, 1: int, 2: Channel}
     */
    private static function bootListener(ExecutionScope $scope, float $holdSeconds): array
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        self::assertTrue($listener->bind(self::HOST, 0), "socket bind: {$listener->errMsg}");
        self::assertTrue($listener->listen(), "socket listen: {$listener->errMsg}");

        $name = $listener->getsockname();
        self::assertIsArray($name);
        self::assertArrayHasKey('port', $name);
        $port = (int) $name['port'];

        $serverDone = new Channel(1);

        $scope->go(static function (ExecutionScope $serverScope) use ($listener, $serverDone, $holdSeconds): void {
            try {
                $conn = $listener->accept(5);
                if ($conn === false) {
                    return;
                }

                $req = '';
                while (!str_contains($req, "\r\n\r\n")) {
                    $piece = $conn->recv(4096, 5);
                    if ($piece === false || $piece === '') {
                        break;
                    }
                    $req .= $piece;
                }

                $key = self::extractHeader($req, 'sec-websocket-key');
                $accept = base64_encode(sha1($key . self::WEBSOCKET_GUID, true));

                $conn->sendAll(
                    "HTTP/1.1 101 Switching Protocols\r\n"
                    . "Upgrade: websocket\r\n"
                    . "Connection: Upgrade\r\n"
                    . "Sec-WebSocket-Accept: {$accept}\r\n"
                    . "\r\n",
                );

                $conn->sendAll(Frame::pack(
                    'hold-open',
                    WebSocketServer::WEBSOCKET_OPCODE_TEXT,
                    WebSocketServer::WEBSOCKET_FLAG_FIN,
                ));

                if ($holdSeconds > 0.0) {
                    // hold the connection open so the client can decide when to leave.
                    // drain client bytes until the client closes its side.
                    $deadline = microtime(true) + $holdSeconds;
                    while (microtime(true) < $deadline) {
                        $serverScope->throwIfCancelled();
                        $piece = $conn->recv(4096, 1);
                        if ($piece === false || $piece === '') {
                            break;
                        }
                    }
                }

                $conn->close();
            } finally {
                $serverDone->push(true);
            }
        });

        return [$listener, $port, $serverDone];
    }

    private static function extractHeader(string $request, string $name): string
    {
        $lines = preg_split("/\r\n/", $request) ?: [];
        $needle = strtolower($name) . ':';
        foreach ($lines as $line) {
            $lower = strtolower($line);
            if (str_starts_with($lower, $needle)) {
                return trim(substr($line, strlen($name) + 1));
            }
        }
        return '';
    }
}
