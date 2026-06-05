<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Hash;

/**
 * Final — sealed sentinel exception; no extension surface.
 *
 * Raised when {@see Canonical::normalize()} encounters a value it cannot
 * canonicalize deterministically — an arbitrary object that does not
 * implement {@see Canonicalizable}, a resource, a closure, NaN, or
 * Infinity. Always indicates a programming error: hashable surfaces
 * must be made of declared canonical shapes.
 */
final class UncanonicalizableValue extends \InvalidArgumentException
{
}
