<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Clock;

use Phalanx\Panoply\Clock;

/**
 * Production {@see Clock} implementation. Reads the OS clock via
 * `microtime(true)` (sub-millisecond precision) and `new \DateTimeImmutable()`
 * (current wall time).
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
        return (int) (microtime(true) * 1_000_000);
    }
}
