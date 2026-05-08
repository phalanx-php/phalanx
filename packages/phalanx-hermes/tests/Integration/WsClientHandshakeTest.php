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
use Phalanx\Hermes\WsCloseCode;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof for the OpenSwoole-native WebSocket client handshake.
 *
 * Spins up a coroutine-native TCP listener on an ephemeral port inside the
 * test coroutine, accepts a single client connection, hand-crafts the
 * RFC6455 101 Switching Protocols response (Sec-WebSocket-Accept computed
 * against the spec GUID), pushes a single OpenSwoole-framed text payload,
 * then waits for the client's close frame before tearing the listener down.
 */
final class WsClientHandshakeTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    #[Test]
    public function handshakeYieldsFirstFrameAndClosesCleanly(): void
    {
        Application::starting()
            ->providers(Hermes::services())
            ->run(Task::named(
                'test.hermes.client.handshake',
                static function (ExecutionScope $scope): void {
                    $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
                    self::assertTrue($listener->bind(self::HOST, 0), "socket bind: {$listener->errMsg}");
                    self::assertTrue($listener->listen(), "socket listen: {$listener->errMsg}");

                    $name = $listener->getsockname();
                    self::assertIsArray($name);
                    self::assertArrayHasKey('port', $name);
                    $port = (int) $name['port'];
                    $url = 'ws://' . self::HOST . ':' . $port . '/echo';

                    $serverDone = new Channel(1);

                    $scope->go(static function (ExecutionScope $serverScope) use ($listener, $serverDone): void {
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

                            $head = "HTTP/1.1 101 Switching Protocols\r\n"
                                . "Upgrade: websocket\r\n"
                                . "Connection: Upgrade\r\n"
                                . "Sec-WebSocket-Accept: {$accept}\r\n"
                                . "\r\n";
                            $conn->sendAll($head);

                            $conn->sendAll(Frame::pack(
                                'phalanx-ws',
                                WebSocketServer::WEBSOCKET_OPCODE_TEXT,
                                WebSocketServer::WEBSOCKET_FLAG_FIN,
                            ));

                            // Drain whatever the client sends until peer EOF.
                            while (true) {
                                $serverScope->throwIfCancelled();
                                $piece = $conn->recv(4096, 1);
                                if ($piece === false || $piece === '') {
                                    break;
                                }
                            }
                            $conn->close();
                        } finally {
                            $listener->close();
                            $serverDone->push(true);
                        }
                    });

                    $client = $scope->service(WsClient::class);
                    $connection = $client->connect($scope, $url);

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
                        $connection->close(WsCloseCode::Normal, 'test_done');
                        self::assertTrue($serverDone->pop(2), 'server fixture did not signal done within 2s');
                    }
                },
            ));
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
