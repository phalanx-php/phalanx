<?php

declare(strict_types=1);

namespace Phalanx\Iris\Tests\Integration;

use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Coroutine\Socket;
use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;

/**
 * End-to-end proof for the HTTP/1.1 + chunked transport.
 *
 * Spins up a coroutine-native TCP listener, accepts a single connection,
 * hand-crafts an HTTP/1.1 chunked response with three SSE payload chunks
 * emitted at 30ms intervals, then runs HttpClient::stream() against it
 * from a sibling coroutine and asserts each chunk decodes through
 * HttpStream::read() in the order written.
 *
 * This pins the chunked decoder + stream framing against an exact
 * known wire so future regressions show up immediately without
 * standing up a real OpenSwoole HTTP server.
 */
final class HttpStreamChunkedTest extends PhalanxTestCase
{
    private const string HOST = '127.0.0.1';

    public function testChunkedSseStreamYieldsEachChunkInOrder(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $listener = new Socket(AF_INET, SOCK_STREAM, IPPROTO_IP);
            self::assertTrue($listener->bind(self::HOST, 0), "socket bind: {$listener->errMsg}");
            self::assertTrue($listener->listen(), "socket listen: {$listener->errMsg}");

            $name = $listener->getsockname();
            self::assertIsArray($name);
            self::assertArrayHasKey('port', $name);
            $port = (int) $name['port'];

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

                    $head = "HTTP/1.1 200 OK\r\n"
                        . "content-type: text/event-stream\r\n"
                        . "transfer-encoding: chunked\r\n"
                        . "\r\n";
                    $conn->sendAll($head);

                    $payloads = [
                        "event: tick\ndata: {\"n\":1}\n\n",
                        "event: tick\ndata: {\"n\":2}\n\n",
                        "event: tick\ndata: {\"n\":3}\n\n",
                        "data: [DONE]\n\n",
                    ];
                    foreach ($payloads as $p) {
                        $size = dechex(strlen($p));
                        $conn->sendAll("{$size}\r\n{$p}\r\n");
                        $serverScope->delay(0.03);
                    }
                    $conn->sendAll("0\r\n\r\n");
                    $conn->close();
                } finally {
                    $listener->close();
                    $serverDone->push(true);
                }
            });

            try {
                $client = new HttpClient();
                $stream = $client->stream($scope, new HttpRequest(
                    'GET',
                    "http://127.0.0.1:{$port}/",
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
            } finally {
                self::assertTrue($serverDone->pop(2));
            }
        });
    }
}
