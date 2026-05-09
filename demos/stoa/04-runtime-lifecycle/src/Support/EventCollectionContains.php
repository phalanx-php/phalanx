<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

final readonly class EventCollectionContains
{
    public function __invoke(string $host, int $port, string $event, ?string $resource = null): bool
    {
        $findFirst = new FirstEventFinder();

        return $findFirst($host, $port, $event, $resource) !== null;
    }
}
