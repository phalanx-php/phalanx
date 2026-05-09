<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

use OpenSwoole\Coroutine;

final readonly class HttpStatusWaiter
{
    public function __invoke(string $host, int $port, string $path, int $expectedStatus, float $deadline = 3.0): bool
    {
        $httpGet = new SimpleHttpGet();
        $until = microtime(true) + $deadline;

        do {
            $response = $httpGet($host, $port, $path);
            if ($response['status'] === $expectedStatus) {
                return true;
            }
            Coroutine::usleep(50_000);
        } while (microtime(true) < $until);

        return false;
    }
}
