<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Support;

use OpenSwoole\Constant;
use OpenSwoole\Coroutine\Client;

final readonly class SseFrameReader
{
    /**
     * @return array{status: int, headers: string, frames: list<array{event?: string, id?: string, data: string}>}
     */
    public function __invoke(string $host, int $port, string $path, int $expectedFrames, float $timeout): array
    {
        $client = new Client(Constant::SOCK_TCP);
        if (!$client->connect($host, $port, 0.5)) {
            return ['status' => 0, 'headers' => '', 'frames' => []];
        }

        $client->send("GET {$path} HTTP/1.1\r\nHost: {$host}:{$port}\r\nAccept: text/event-stream\r\n\r\n");

        [$head, $frames] = self::collectFrames($client, $expectedFrames, $timeout);
        $client->close();

        preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m);
        return ['status' => (int) ($m[1] ?? 0), 'headers' => $head, 'frames' => $frames];
    }

    /**
     * @return array{string, list<array{event?: string, id?: string, data: string}>}
     */
    private static function collectFrames(Client $client, int $expectedFrames, float $timeout): array
    {
        $parseFrame = new SseFrameParser();
        $deadline = microtime(true) + $timeout;
        $raw = '';
        $frames = [];
        $headersComplete = false;
        $head = '';

        while (microtime(true) < $deadline && count($frames) < $expectedFrames) {
            $chunk = $client->recv(0.2);
            if ($chunk === false || $chunk === '') {
                continue;
            }
            $raw .= $chunk;

            if (!$headersComplete) {
                $boundary = strpos($raw, "\r\n\r\n");
                if ($boundary !== false) {
                    $head = substr($raw, 0, $boundary);
                    $raw = substr($raw, $boundary + 4);
                    $headersComplete = true;
                }
            }

            if ($headersComplete) {
                while (($boundary = strpos($raw, "\n\n")) !== false) {
                    $frameText = substr($raw, 0, $boundary);
                    $raw = substr($raw, $boundary + 2);
                    $frame = $parseFrame($frameText);
                    if ($frame !== null) {
                        $frames[] = $frame;
                    }
                }
            }
        }

        return [$head, $frames];
    }
}
