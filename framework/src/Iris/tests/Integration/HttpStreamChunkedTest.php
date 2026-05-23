<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Integration;

use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Suspendable;
use Phalanx\System\TcpConnection;
use Phalanx\System\TlsOptions;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end proof for the HTTP/1.1 + chunked transport.
 *
 * Uses a scripted TCP connection to hand HttpClient::stream() an HTTP/1.1
 * chunked response with three SSE payload chunks, then asserts each chunk
 * decodes through HttpStream::read() in the order written.
 *
 * This pins the chunked decoder + stream framing against an exact
 * known wire so future regressions show up immediately without
 * standing up a real OpenSwoole HTTP server.
 */
final class HttpStreamChunkedTest extends TestCase
{
    private const string HOST = '127.0.0.1';

    public function testChunkedSseStreamYieldsEachChunkInOrder(): void
    {
        $connection = ScriptedTcpConnection::withResponseChunks([
            "HTTP/1.1 200 OK\r\n"
                . "content-type: text/event-stream\r\n"
                . "transfer-encoding: chunked\r\n"
                . "\r\n",
            self::chunk("event: tick\ndata: {\"n\":1}\n\n"),
            self::chunk("event: tick\ndata: {\"n\":2}\n\n"),
            self::chunk("event: tick\ndata: {\"n\":3}\n\n"),
            self::chunk("data: [DONE]\n\n"),
            "0\r\n\r\n",
        ]);

        TestScope::compile()
            ->shutdownAfterRun()
            ->run(static function (ExecutionScope $scope) use ($connection): void {
                $client = new HttpClient(
                    tcpFactory: static fn(
                        string $_scheme,
                        string $_host,
                        ?TlsOptions $_tlsOptions,
                    ): TcpConnection => $connection,
                );
                $stream = $client->stream($scope, new HttpRequest(
                    'GET',
                    'http://' . self::HOST . ':8123/',
                    ['accept' => ['text/event-stream']],
                ));

                $events = [];
                $accumulated = '';
                while (!$stream->eof) {
                    $chunk = $stream->read($scope);
                    if ($chunk === '') {
                        break;
                    }
                    $accumulated .= $chunk;
                    while (($delim = strpos($accumulated, "\n\n")) !== false) {
                        $events[] = substr($accumulated, 0, $delim);
                        $accumulated = (string) substr($accumulated, $delim + 2);
                    }
                }
                $stream->close();

                self::assertSame(200, $stream->status, 'status line decoded');
                self::assertSame('text/event-stream', $stream->headers['content-type'] ?? '');
                self::assertCount(4, $events, 'three ticks plus [DONE]');
                self::assertStringContainsString('"n":1', $events[0]);
                self::assertStringContainsString('"n":2', $events[1]);
                self::assertStringContainsString('"n":3', $events[2]);
                self::assertStringContainsString('[DONE]', $events[3]);
            });

        self::assertTrue($connection->closed);
        self::assertStringContainsString("GET / HTTP/1.1\r\n", $connection->sent);
    }

    private static function chunk(string $payload): string
    {
        return dechex(strlen($payload)) . "\r\n{$payload}\r\n";
    }
}

final class ScriptedTcpConnection implements TcpConnection
{
    public string $sent = '';

    public bool $closed = false;

    /** @param list<string> $responseChunks */
    private function __construct(private array $responseChunks)
    {
    }

    /** @param list<string> $responseChunks */
    public static function withResponseChunks(array $responseChunks): self
    {
        return new self($responseChunks);
    }

    public function connect(Suspendable $_scope, string $_host, int $_port, float $_timeout = 1.0): bool
    {
        return true;
    }

    public function send(Suspendable $_scope, string $payload, float $_timeout = 1.0): int
    {
        $this->sent .= $payload;

        return strlen($payload);
    }

    public function recv(Suspendable $_scope, float $_timeout = 1.0): ?string
    {
        return array_shift($this->responseChunks) ?? '';
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
