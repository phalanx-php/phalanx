<?php

declare(strict_types=1);

namespace Acme\HttpDemo\Runtime\Support;

use Swoole\Coroutine;

final readonly class EventWaiter
{
    public function __invoke(string $host, int $port, string $event, float $timeout, ?string $resource = null): bool
    {
        $contains = new EventCollectionContains();
        $deadline = microtime(true) + $timeout;

        do {
            if ($contains($host, $port, $event, $resource)) {
                return true;
            }
            Coroutine::sleep(0.025);
        } while (microtime(true) < $deadline);

        return false;
    }
}
