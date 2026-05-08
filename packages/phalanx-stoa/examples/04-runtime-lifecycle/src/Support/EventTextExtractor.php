<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

final readonly class EventTextExtractor
{
    public function __invoke(string $host, int $port, string $event, string $fallback, ?string $resource = null): string
    {
        $findFirst = new FirstEventFinder();
        $entry = $findFirst($host, $port, $event, $resource);

        if ($entry === null) {
            return "missing {$event}";
        }

        $path = (string) ($entry['context']['path'] ?? '');

        return $path !== ''
            ? "{$fallback} ({$path})"
            : $fallback;
    }
}
