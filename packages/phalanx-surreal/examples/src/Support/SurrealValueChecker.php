<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Examples\Support;

/**
 * Recursively searches a SurrealDB query result for a scalar value
 * equal to the given expected value.
 */
final class SurrealValueChecker
{
    public function __invoke(mixed $value, mixed $expected): bool
    {
        if ($value === $expected) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (($this)($item, $expected)) {
                return true;
            }
        }

        return false;
    }
}
