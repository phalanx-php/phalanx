<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Runtime\Support;

final readonly class EventResourceMatcher
{
    /**
     * @param array{event: string, context: array<string, mixed>, at: float} $entry
     */
    public function __invoke(array $entry, ?string $resource): bool
    {
        if ($resource === null || $resource === '') {
            return true;
        }

        return ($entry['context']['resource'] ?? null) === $resource;
    }
}
