<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Support;

use OpenSwoole\Constant;
use OpenSwoole\Coroutine\Client;

final readonly class RawHttpRequest
{
    /**
     * @param list<string> $extraHeaders
     * @return array{status: int, headers: string, body: string}
     */
    public function __invoke(string $host, int $port, string $method, string $path, array $extraHeaders = []): array
    {
        $client = new Client(Constant::SOCK_TCP);
        if (!$client->connect($host, $port, 0.5)) {
            return ['status' => 0, 'headers' => '', 'body' => ''];
        }

        $headers = ["Host: {$host}:{$port}", 'Connection: close', ...$extraHeaders];
        $client->send("{$method} {$path} HTTP/1.1\r\n" . implode("\r\n", $headers) . "\r\n\r\n");

        $raw = self::readAll($client);
        $client->close();

        return self::parseResponse($raw);
    }

    private static function readAll(Client $client): string
    {
        $raw = '';
        while (true) {
            $chunk = $client->recv(0.5);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        return $raw;
    }

    /** @return array{status: int, headers: string, body: string} */
    private static function parseResponse(string $raw): array
    {
        [$head, $body] = str_contains($raw, "\r\n\r\n")
            ? [substr($raw, 0, strpos($raw, "\r\n\r\n")), substr($raw, strpos($raw, "\r\n\r\n") + 4)]
            : [$raw, ''];
        preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $head, $m);
        return ['status' => (int) ($m[1] ?? 0), 'headers' => $head, 'body' => $body];
    }
}
