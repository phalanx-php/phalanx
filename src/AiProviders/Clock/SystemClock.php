<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Clock;

use Phalanx\AiProviders\Clock;

/**
 * Production {@see Clock} implementation.
 *
 * `now()` returns the current wall-clock time via `new \DateTimeImmutable()`.
 * This is suitable for human-visible Cue timestamps.
 *
 * `nowMicroseconds()` returns a monotonic microsecond counter derived from
 * `hrtime(true)` (nanosecond resolution). It is strictly non-decreasing and
 * immune to NTP step-adjustments, making it correct for coalescing-window
 * arithmetic. The epoch anchor is arbitrary — do not compare these values
 * against wall-clock timestamps.
 *
 * Stateless — safe to share across scopes and coroutines.
 *
 * Final — sealed against subclassing; inject the interface, not this class.
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    public function nowMicroseconds(): int
    {
        return intdiv(hrtime(true), 1_000);
    }
}
