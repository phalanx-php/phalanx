<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Clock;

use Phalanx\Panoply\Clock;
use Phalanx\Panoply\Duration;

/**
 * Test seam only. Holds a fixed microsecond counter that advances only when
 * {@see self::advance()} or {@see self::set()} are called explicitly.
 *
 * `nowMicroseconds()` is the controllable monotonic seam used by coalescing
 * tests. Construct with any initial value (e.g. `0` or `1_000_000`); advance
 * it between stream items to simulate elapsed time without touching the OS
 * clock. The value is arbitrary — it represents relative elapsed time, not
 * wall-clock epoch.
 *
 * `now()` derives a `\DateTimeImmutable` from the same counter for the rare
 * cases where a wall-clock-like value is needed in tests. It is NOT
 * synchronized with the production `SystemClock::now()` wall-clock source —
 * the two sources are intentionally independent (see {@see Clock} docblock).
 *
 * WARNING — {@see self::set()} may move time backward (non-monotonic) on
 * purpose to support scenario-style tests. Do NOT use this class in
 * production code or in any path that depends on the Clock monotonicity
 * guarantee. Production code must construct {@see \Phalanx\Panoply\Clock\SystemClock},
 * which uses `hrtime(true)` and is strictly non-decreasing.
 *
 * The backing field is `private int` — mutable by design. The Clock
 * interface is a test seam, not a value object, so `readonly` and
 * `private(set)` are intentionally absent.
 *
 * Final — sealed against subclassing; not a production type.
 *
 * @internal
 */
final class FrozenClock implements Clock
{
    public function __construct(private int $microseconds)
    {
    }

    /**
     * Move the clock forward by the given duration.
     */
    public function advance(Duration $by): void
    {
        $this->microseconds += $by->toMicroseconds();
    }

    /**
     * Set the clock to an absolute epoch-microsecond value.
     */
    public function set(int $microseconds): void
    {
        $this->microseconds = $microseconds;
    }

    public function nowMicroseconds(): int
    {
        return $this->microseconds;
    }

    public function now(): \DateTimeImmutable
    {
        $sec = intdiv($this->microseconds, 1_000_000);
        $micro = $this->microseconds % 1_000_000;

        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $sec, $micro));
        if ($dt === false) {
            // Fallback: construct from epoch seconds only (microseconds truncated)
            return new \DateTimeImmutable()->setTimestamp($sec);
        }

        return $dt;
    }
}
