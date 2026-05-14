<?php

declare(strict_types=1);

namespace Phalanx\Trace;

final class TraceEvent
{
    /** @param array<string, mixed> $attrs */
    public function __construct(
        private(set) TraceType $type,
        private(set) string $name,
        private(set) float $timestamp,
        private(set) array $attrs,
    ) {
    }
}
