<?php

declare(strict_types=1);

namespace Phalanx\Tests\Integration\System;

use OpenSwoole\Coroutine;
use Phalanx\Scope\ExecutionScope;
use Phalanx\System\HttpClient;
use Phalanx\System\HttpRequest;
use Phalanx\Tests\Support\CoroutineTestCase;

/**
 * End-to-end proof for the HTTP/1.1 + chunked transport.
 *
 * Spins up an in-process TCP listener (PHP's stream_socket_server, which
 * OpenSwoole's runtime hooks make coroutine-aware), accepts a single
 * connection, hand-crafts an HTTP/1.1 chunked response with three SSE
 * payload chunks emitted at 30ms intervals, then runs HttpClient::stream()
 * against it from a sibling coroutine and asserts each chunk decodes
 * through HttpStream::read() in the order written.
 *
 * This pins the chunked decoder + stream framing against an exact
 * known wire so future regressions show up immediately without
 * standing up a real OpenSwoole HTTP server.
 */
final class HttpStreamChunkedTest extends CoroutineTestCase
{
    private const HOST = '127.0.0.1';

    public function testChunkedSseStreamYieldsEachChunkInOrder(): void
    {
        $this->runScoped(static function (ExecutionScope $scope): void {
            $listener = stream_socket_server('tcp://' . self::HOST . ':0', $errno, $errstr);
            self::assertNotFalse($listener, "stream_socket_server: {$errstr} ({$errno})");
            $name = stream_socket_get_name($listener, false);
            self::assertNotFalse($name);
            $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

            $serverDone = new Coroutine\Channel(1);
            Coroutine::create(static function () use ($listener, $serverDone): void {
                try {
                    $conn = stream_socket_accept($listener, 5);
                    if ($conn === false) {
                        return;
                    }
                    // Drain the request headers; we don't care what they say.
                    $req = '';
                    while (!str_contains($req, "\r\n\r\n")) {
                        $piece = fread($conn, 4096);
                        if ($piece === false || $piece === '') {
                            break;
                        }
                        $req .= $piece;
                    }

                    $head = "HTTP/1.1 200 OK\r\n"
                        . "content-type: text/event-stream\r\n"
                        . "transfer-encoding: chunked\r\n"
                        . "\r\n";
                    fwrite($conn, $head);

                    $payloads = [
                        "event: tick\ndata: {\"n\":1}\n\n",
                        "event: tick\ndata: {\"n\":2}\n\n",
                        "event: tick\ndata: {\"n\":3}\n\n",
                        "data: [DONE]\n\n",
                    ];
                    foreach ($payloads as $p) {
                        $size = dechex(strlen($p));
                        fwrite($conn, "{$size}\r\n{$p}\r\n");
                        Coroutine::usleep(30_000);
                    }
                    fwrite($conn, "0\r\n\r\n");
                    fclose($conn);
                } finally {
                    fclose($listener);
                    $serverDone->push(true);
                }
            });

            try {
                $client = new HttpClient(self::HOST, $port, tls: false);
                $stream = $client->stream($scope, new HttpRequest('GET', '/', '', [
                    'accept' => 'text/event-stream',
                ]));

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
                $serverDone->pop(2);
            }
        });
    }
}
