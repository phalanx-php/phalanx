<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Support;

use Closure;
use Phalanx\Scope\ExecutionScope;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use Swoole\WebSocket\Frame;

use function base64_encode;
use function chr;
use function microtime;
use function ord;
use function preg_split;
use function sha1;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use function unpack;

/**
 * Small RFC6455 server fixture for client integration tests.
 *
 * The production WebSocket server is intentionally not involved here. These
 * tests need a strict peer that can prove handshake, frame masking, writer
 * serialisation, and close behavior at the socket boundary.
 */
final class Rfc6455TestServer
{
    public const string HOST = '127.0.0.1';

    private const string WEBSOCKET_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /**
     * @param Channel<true> $done
     */
    private function __construct(
        private readonly Socket $listener,
        private readonly Channel $done,
        public readonly int $port,
    ) {
    }

    /**
     * @param Closure(Socket, ExecutionScope): void $handler
     */
    public static function start(ExecutionScope $scope, Closure $handler): self
    {
        $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
        Assert::assertTrue($listener->bind(self::HOST, 0), "socket bind: {$listener->errMsg}");
        Assert::assertTrue($listener->listen(), "socket listen: {$listener->errMsg}");

        $name = $listener->getsockname();
        Assert::assertIsArray($name);
        Assert::assertArrayHasKey('port', $name);

        $server = new self($listener, new Channel(1), (int) $name['port']);

        $scope->go(static function (ExecutionScope $serverScope) use ($server, $handler): void {
            $conn = null;

            try {
                $conn = $server->listener->accept(5);
                if ($conn === false) {
                    return;
                }

                self::acceptHandshake($conn);
                $handler($conn, $serverScope);
            } finally {
                if ($conn instanceof Socket) {
                    $conn->close();
                }

                $server->listener->close();
                $server->done->push(true);
            }
        });

        return $server;
    }

    public function url(string $path): string
    {
        return 'ws://' . self::HOST . ':' . $this->port . $path;
    }

    public function awaitDone(float $timeout = 2.0): bool
    {
        return $this->done->pop($timeout) === true;
    }

    public static function sendText(Socket $conn, string $payload): void
    {
        $conn->sendAll(Frame::pack(
            $payload,
            SWOOLE_WEBSOCKET_OPCODE_TEXT,
            SWOOLE_WEBSOCKET_FLAG_FIN,
        ));
    }

    public static function drainUntilClosed(ExecutionScope $scope, Socket $conn, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $scope->throwIfCancelled();
            $piece = $conn->recv(4096, 1);
            if ($piece === false || $piece === '') {
                break;
            }
        }
    }

    /**
     * @param Channel<string> $frames
     */
    public static function pushClientTextFrames(ExecutionScope $scope, Socket $conn, int $count, Channel $frames): void
    {
        $buffer = '';
        $textFrames = 0;

        while ($textFrames < $count) {
            $scope->throwIfCancelled();
            $chunk = $conn->recv(4096, 5);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;

            while (true) {
                $parsed = self::parseClientFrame($buffer);
                if ($parsed === null) {
                    break;
                }

                [$opcode, $payload, $consumed] = $parsed;
                $buffer = substr($buffer, $consumed);

                if ($opcode === 0x1) {
                    $frames->push($payload);
                    $textFrames++;
                }
            }
        }
    }

    private static function acceptHandshake(Socket $conn): void
    {
        $request = '';

        while (!str_contains($request, "\r\n\r\n")) {
            $piece = $conn->recv(4096, 5);
            if ($piece === false || $piece === '') {
                break;
            }
            $request .= $piece;
        }

        $key = self::extractHeader($request, 'sec-websocket-key');
        if ($key === '') {
            throw new RuntimeException('missing Sec-WebSocket-Key header');
        }

        $accept = base64_encode(sha1($key . self::WEBSOCKET_GUID, true));
        $conn->sendAll(
            "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n"
            . "\r\n",
        );
    }

    /**
     * Parse one RFC6455 client-to-server masked frame from the buffer.
     *
     * @return null|array{0: int, 1: string, 2: int}
     */
    private static function parseClientFrame(string $buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $b0 = ord($buffer[0]);
        $b1 = ord($buffer[1]);
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $length = $b1 & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < $offset + 2) {
                return null;
            }

            $unpacked = unpack('n', substr($buffer, $offset, 2));
            Assert::assertIsArray($unpacked);
            $length = $unpacked[1];
            $offset += 2;
        } elseif ($length === 127) {
            if (strlen($buffer) < $offset + 8) {
                return null;
            }

            $unpacked = unpack('J', substr($buffer, $offset, 8));
            Assert::assertIsArray($unpacked);
            $length = $unpacked[1];
            $offset += 8;
        }

        if (!$masked) {
            Assert::fail('client frame arrived without RFC6455 mask bit set');
        }

        if (strlen($buffer) < $offset + 4 + $length) {
            return null;
        }

        $mask = substr($buffer, $offset, 4);
        $offset += 4;
        $payload = substr($buffer, $offset, $length);
        $offset += $length;

        $unmasked = '';
        for ($i = 0; $i < $length; $i++) {
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
