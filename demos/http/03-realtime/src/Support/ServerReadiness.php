<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Realtime\Support;

use Swoole\Coroutine;

final readonly class ServerReadiness
{
    public function __invoke(string $host, int $port, string $healthPath = '/realtime/health', float $deadline = 3.0): bool
    {
        $rawRequest = new RawHttpRequest();
        $until = microtime(true) + $deadline;

        do {
            $response = $rawRequest($host, $port, 'GET', $healthPath);
            if ($response['status'] === 200) {
                return true;
            }
            Coroutine::sleep(0.05);
        } while (microtime(true) < $until);

        return false;
    }
}
