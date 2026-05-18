<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Clock;

use Phalanx\Panoply\Clock;
use Phalanx\Panoply\Duration;

/**
 * Test seam. Holds a fixed epoch-microsecond counter that advances only
 * when {@see self::advance()} or {@see self::set()} are called explicitly.
 *
 * Construct with an initial epoch value (e.g. `1_000_000` for 1970-01-01
 * 00:00:01 UTC). Mutate via {@see self::advance()} between stream items in
 * coalescing tests to simulate elapsed time without touching the OS clock.
 *
 * Note: the backing field is `private int` — mutable by design. The Clock
 * interface is a test seam, not a value object, so `readonly` and
 * `private(set)` are intentionally absent.
 *
 * Final — sealed against subclassing; not a production type.
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
        $sec   = intdiv($this->microseconds, 1_000_000);
        $micro = $this->microseconds % 1_000_000;

        $dt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $sec, $micro));
        if ($dt === false) {
            // Fallback: construct from epoch seconds only (microseconds truncated)
            return new \DateTimeImmutable()->setTimestamp($sec);
        }

        return $dt;
    }
}
