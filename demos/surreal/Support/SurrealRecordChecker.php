<?php

declare(strict_types=1);

namespace Phalanx\Demos\Surreal\Support;

/**
 * Recursively searches a SurrealDB query result for a record whose `name`
 * field matches the given string.
 */
final class SurrealRecordChecker
{
    public function __invoke(mixed $value, string $name): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if (($value['name'] ?? null) === $name) {
            return true;
        }

        foreach ($value as $item) {
            if (($this)($item, $name)) {
                return true;
            }
        }

        return false;
    }
}
