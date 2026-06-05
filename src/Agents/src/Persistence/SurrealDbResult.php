<?php

declare(strict_types=1);

namespace Phalanx\Agents\Persistence;

final class SurrealDbResult
{
    /**
     * @param list<mixed>|null $results
     * @return list<array<string, mixed>>
     */
    public static function firstRows(?array $results): array
    {
        if ($results === null || $results === []) {
            return [];
        }

        $first = $results[0];
        if (!is_array($first)) {
            return [];
        }

        /** @var list<array<string, mixed>> $first */
        return $first;
    }
}
