<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Internal;

use BackedEnum;
use UnitEnum;

final class CanonicalHash
{
    /**
     * @param array<int|string, mixed> $canonical
     */
    public static function of(array $canonical): string
    {
        return hash('sha256', self::bytes($canonical));
    }

    /**
     * @param array<int|string, mixed> $canonical
     */
    private static function bytes(array $canonical): string
    {
        return json_encode(
            self::normalize($canonical),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null,
            is_bool($value),
            is_int($value),
            is_string($value) => $value,
            is_float($value) => self::normalizeFloat($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => self::normalizeArray($value),
            default => throw new \InvalidArgumentException(
                'Cannot canonicalize value of type ' . get_debug_type($value),
            ),
        };
    }

    private static function normalizeFloat(float $value): float
    {
        if (is_nan($value) || is_infinite($value)) {
            throw new \InvalidArgumentException('NaN and Infinity are not canonicalizable.');
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>|list<mixed>
     */
    private static function normalizeArray(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $keys = array_keys($value);
        sort($keys, SORT_STRING);

        $out = [];
        foreach ($keys as $key) {
            $out[(string) $key] = self::normalize($value[$key]);
        }

        return $out;
    }
}
