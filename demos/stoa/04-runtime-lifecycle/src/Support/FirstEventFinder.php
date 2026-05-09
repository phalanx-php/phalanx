<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

final readonly class FirstEventFinder
{
    /**
     * @return array{event: string, context: array<string, mixed>, at: float}|null
     */
    public function __invoke(string $host, int $port, string $event, ?string $resource = null): ?array
    {
        $readEvents = new RuntimeEventReader();
        $matchesResource = new EventResourceMatcher();

        foreach ($readEvents($host, $port) as $entry) {
            if ($entry['event'] === $event && $matchesResource($entry, $resource)) {
                return $entry;
            }
        }

        return null;
    }
}
