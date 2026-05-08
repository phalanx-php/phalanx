<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

use OpenSwoole\Coroutine\Client;

final readonly class SimpleHttpGet
{
    /**
     * @return array{status: int, body: string}
     */
    public function __invoke(string $host, int $port, string $path): array
    {
        $open = new RawConnectionOpener();
        $client = $open($host, $port, $path);

        if ($client === null) {
            return ['status' => 0, 'body' => ''];
        }

        $raw = self::readAll($client);
        $client->close();

        return self::parseResponse($raw);
    }

    private static function readAll(Client $client): string
    {
        $raw = '';
        while (true) {
            $chunk = $client->recv(0.25);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $raw .= $chunk;
        }
        return $raw;
    }

    /** @return array{status: int, body: string} */
    private static function parseResponse(string $raw): array
    {
        preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $raw, $matches);
        $body = str_contains($raw, "\r\n\r\n")
            ? substr($raw, strpos($raw, "\r\n\r\n") + 4)
            : '';

        return ['status' => (int) ($matches[1] ?? 0), 'body' => $body];
    }
}
