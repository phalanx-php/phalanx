<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Hash;

/**
 * Marker for value objects that supply their own canonical form for
 * {@see Canonical} hashing. Implementations return an array whose keys
 * and values are themselves canonicalizable (scalars, lists, associative
 * arrays, enums, or other Canonicalizable objects).
 *
 * Use this when default public-property reflection produces an unstable
 * or noisy form — for example, when transient cache fields, runtime
 * references, or computed views would otherwise leak into the hash.
 */
interface Canonicalizable
{
    /**
     * @return array<int|string, mixed>
     */
    public function toCanonical(): array;
}
