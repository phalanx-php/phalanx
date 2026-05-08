<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

use OpenSwoole\Constant;
use OpenSwoole\Coroutine\Client;

final readonly class RawConnectionOpener
{
    public function __invoke(string $host, int $port, string $path): ?Client
    {
        $client = new Client(Constant::SOCK_TCP);

        if (!$client->connect($host, $port, 0.5)) {
            return null;
        }

        $client->send(
            "GET {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Connection: close\r\n"
            . "\r\n",
        );

        return $client;
    }
}
