<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Clock;

use Phalanx\Panoply\Clock\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    #[Test]
    public function nowReturnsDateTimeImmutable(): void
    {
        $clock = new SystemClock();

        // now() is the wall-clock source — verify the two now() calls both
        // return DateTimeImmutable. Do NOT assert epoch correspondence with
        // nowMicroseconds(): the two sources are intentionally independent
        // (now() = wall-clock, nowMicroseconds() = hrtime-based monotonic).
        self::assertInstanceOf(\DateTimeImmutable::class, $clock->now());
        self::assertInstanceOf(\DateTimeImmutable::class, $clock->now());
    }

    #[Test]
    public function nowMicrosecondsReturnsPositiveInteger(): void
    {
        $clock = new SystemClock();

        // nowMicroseconds() is hrtime(true)/1000 — always > 0 after process start.
        self::assertGreaterThan(0, $clock->nowMicroseconds());
    }

    #[Test]
    public function nowMicrosecondsIsMonotonicAcrossTwoCalls(): void
    {
        // nowMicroseconds() uses hrtime(true) — the monotonic clock. Two
        // consecutive reads must be non-decreasing even under NTP adjustments.
        $clock = new SystemClock();

        $first  = $clock->nowMicroseconds();
        $second = $clock->nowMicroseconds();

        self::assertGreaterThanOrEqual($first, $second);
    }
}
