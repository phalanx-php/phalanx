<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Hash;

use BackedEnum;
use UnitEnum;

/**
 * Final — static-only canonicalization algorithm; subclassing would
 * diverge byte output and break hash stability.
 *
 * Stable SHA-256 hex hashes over canonical-encoded values. Canonical
 * encoding sorts associative-array keys lexicographically, preserves
 * UTF-8, omits whitespace, normalizes floats deterministically, and
 * emits ISO timestamps via the producing value object's own
 * {@see Canonicalizable::toCanonical()}.
 *
 * Hashes are stable across PHP versions, key insertion order, and host
 * platforms — safe to use as cache keys, audit IDs, provider-debugging
 * fingerprints, and replay markers.
 *
 * Acceptance is strict by design: scalars, null, lists, associative
 * arrays, enums (backed and pure), and objects implementing
 * {@see Canonicalizable}. Arbitrary objects are rejected — passing an
 * unrelated object through `normalize()` raises
 * {@see UncanonicalizableValue}. This prevents accidental fingerprint
 * drift from objects that change shape between releases (scopes,
 * channels, closures, services).
 */
final class Canonical
{
    public static function of(mixed $value): string
    {
        return hash('sha256', self::bytes($value));
    }

    public static function bytes(mixed $value): string
    {
        return self::encode(self::normalize($value));
    }

    /**
     * Public for tests, fixture authoring, and external verifiers that
     * need to canonicalize without hashing.
     */
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value === null,
            is_bool($value),
            is_int($value),
            is_float($value),
            is_string($value) => self::normalizeScalar($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => self::normalizeArray($value),
            is_object($value) => self::normalizeObject($value),
            default => throw new UncanonicalizableValue(
                'Cannot canonicalize value of type ' . get_debug_type($value),
            ),
        };
    }

    private static function encode(mixed $value): string
    {
        $bytes = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
        );

        if ($bytes === false) {
            throw new \RuntimeException('Canonical encoding failed');
        }

        return $bytes;
    }

    private static function normalizeScalar(mixed $value): mixed
    {
        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new UncanonicalizableValue('NaN and Infinity are not canonicalizable');
            }

            // Preserve floats verbatim; JSON_PRESERVE_ZERO_FRACTION emits
            // `42.0` as `42.0` so float and int hashes remain distinct.
            return $value;
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

    /**
     * @return array<int|string, mixed>
     */
    private static function normalizeObject(object $value): array
    {
        if (!$value instanceof Canonicalizable) {
            throw new UncanonicalizableValue(sprintf(
                'Object %s is not Canonicalizable; implement Canonicalizable::toCanonical() to opt in.',
                $value::class,
            ));
        }

        $normalized = self::normalize($value->toCanonical());

        if (!is_array($normalized)) {
            throw new \RuntimeException(sprintf(
                '%s::toCanonical() must return an array; got %s',
                $value::class,
                get_debug_type($normalized),
            ));
        }

        return $normalized;
    }
}
