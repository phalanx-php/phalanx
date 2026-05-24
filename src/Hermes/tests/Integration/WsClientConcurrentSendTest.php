<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Integration;

use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Socket;
use Phalanx\Application;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Hermes;
use Phalanx\Hermes\WsCloseCode;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Two writer coroutines call WsClient->send() simultaneously. The on-the-wire
 * bytes must decode into two complete, non-interleaved RFC6455 frames in some
 * order; that contract is what the writer-side Channel serialisation
 * guarantees and what real-world peers depend on.
 */
final class WsClientConcurrentSendTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    #[Test]
    public function concurrentSendsProduceNonInterleavedFrames(): void
    {
        Application::starting()
            ->providers(Hermes::services())
            ->run(Task::named(
                'test.hermes.client.concurrent-send',
                static function (ExecutionScope $scope): void {
                    $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
                    self::assertTrue($listener->bind(self::HOST, 0), "bind: {$listener->errMsg}");
                    self::assertTrue($listener->listen(), "listen: {$listener->errMsg}");
                    $port = (int) $listener->getsockname()['port'];
                    $url = 'ws://' . self::HOST . ':' . $port . '/echo';

                    $framesChannel = new Channel(4);
                    $serverDone = new Channel(1);

                    $scope->go(static function (ExecutionScope $serverScope) use (
                        $listener,
                        $framesChannel,
                        $serverDone
                    ): void {
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

                            // Drain bytes; parse each complete RFC6455 frame; push payload
                            // onto the assertion channel. Stop after two text frames.
                            $buffer = '';
                            $textFrames = 0;
                            while ($textFrames < 2) {
                                $serverScope->throwIfCancelled();
                                $chunk = $conn->recv(4096, 5);
                                if ($chunk === false || $chunk === '') {
                                    break;
                                }
                                $buffer .= $chunk;

                                while (true) {
                                    $parsed = self::parseFrame($buffer);
                                    if ($parsed === null) {
                                        break;
                                    }
                                    [$opcode, $payload, $consumed] = $parsed;
                                    $buffer = substr($buffer, $consumed);

                                    // 0x1 = text. ignore pings/closes.
                                    if ($opcode === 0x1) {
                                        $framesChannel->push($payload);
                                        $textFrames++;
                                    }
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
                        $payloadA = str_repeat('A', 32);
                        $payloadB = str_repeat('B', 32);

                        $writers = new Channel(2);
                        $scope->go(static function () use ($connection, $payloadA, $writers): void {
                            try {
                                $connection->sendText($payloadA);
                            } finally {
                                $writers->push(true);
                            }
                        });
                        $scope->go(static function () use ($connection, $payloadB, $writers): void {
                            try {
                                $connection->sendText($payloadB);
                            } finally {
                                $writers->push(true);
                            }
                        });
                        self::assertTrue($writers->pop(2), 'first writer coroutine never finished');
                        self::assertTrue($writers->pop(2), 'second writer coroutine never finished');

                        $first = $framesChannel->pop(3);
                        $second = $framesChannel->pop(3);
                        self::assertNotFalse($first, 'first frame never decoded server-side');
                        self::assertNotFalse($second, 'second frame never decoded server-side');

                        $received = [$first, $second];
                        sort($received);
                        $expected = [$payloadA, $payloadB];
                        sort($expected);
                        self::assertSame($expected, $received, 'decoded frames must equal the two sent payloads');
                    } finally {
                        $connection->close(WsCloseCode::Normal, 'test_done');
                        self::assertTrue($serverDone->pop(2), 'server fixture did not signal done');
                    }
                },
            ));
    }

    /**
     * Parse one RFC6455 client-to-server (masked) frame from the buffer.
     *
     * @return null|array{0: int, 1: string, 2: int} [opcode, unmaskedPayload, bytesConsumed]
     */
    private static function parseFrame(string $buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }
        $b0 = ord($buffer[0]);
        $b1 = ord($buffer[1]);
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $len = $b1 & 0x7F;
        $offset = 2;

        if ($len === 126) {
            if (strlen($buffer) < $offset + 2) {
                return null;
            }
            $len = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($len === 127) {
            if (strlen($buffer) < $offset + 8) {
                return null;
            }
            $len = unpack('J', substr($buffer, $offset, 8))[1];
            $offset += 8;
        }

        if (!$masked) {
            // RFC6455 client-to-server frames MUST be masked. Returning
            // garbage here would let a contract-violating implementation pass
            // the round-trip assertion as a false positive — fail loudly.
            self::fail('client frame arrived without RFC6455 mask bit set');
        }

        if (strlen($buffer) < $offset + 4 + $len) {
            return null;
        }
        $mask = substr($buffer, $offset, 4);
        $offset += 4;
        $payload = substr($buffer, $offset, $len);
        $offset += $len;

        $unmasked = '';
        for ($i = 0; $i < $len; $i++) {
            $unmasked .= chr(ord($payload[$i]) ^ ord($mask[$i % 4]));
        }
        return [$opcode, $unmasked, $offset];
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
