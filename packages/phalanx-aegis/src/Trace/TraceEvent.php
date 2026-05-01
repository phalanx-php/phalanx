<?php

declare(strict_types=1);

namespace Phalanx\Trace;

final readonly class TraceEvent
{
    /** @param array<string, mixed> $attrs */
    public function __construct(
        public TraceType $type,
        public string $name,
        public float $timestamp,
        public array $attrs,
    ) {
    }
}
