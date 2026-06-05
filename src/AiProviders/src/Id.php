<?php

declare(strict_types=1);

namespace Phalanx\AiProviders;

use Symfony\Component\Uid\Ulid;

/**
 * Final — static-only ULID utility; no extension surface.
 *
 * Centralized ID generation for ai-providers value objects. All
 * ai-providers-generated IDs (Activity, Invocation, Cue, Effect, Grant,
 * Artifact, default Record id) are ULIDs — lexicographically sortable,
 * 26-char base32, embedded millisecond timestamp.
 */
final class Id
{
    public static function generate(): string
    {
        return (string) new Ulid();
    }

    /**
     * Returns the raw {@see Ulid} object rather than its string form.
     * Useful when you need the timestamp component or want to compare
     * UIDs without parsing.
     */
    public static function ulid(): Ulid
    {
        return new Ulid();
    }

    public static function isValid(\Stringable|string $candidate): bool
    {
        return Ulid::isValid((string) $candidate);
    }
}
