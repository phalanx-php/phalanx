<?php

declare(strict_types=1);

namespace Phalanx\System;

/**
 * Result of a coroutine-aware DNS resolution.
 */
final class DnsLookupResult
{
    public bool $resolved {
        get => $this->addresses !== [];
    }

    /** @param list<string> $addresses */
    public function __construct(
        public readonly string $hostname,
        public readonly array $addresses,
        public readonly int $family = AF_INET,
        public readonly float $durationMs = 0.0,
    ) {
    }

    public function first(): ?string
    {
        return $this->addresses[0] ?? null;
    }
}
