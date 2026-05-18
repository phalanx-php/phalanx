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

        self::assertInstanceOf(\DateTimeImmutable::class, $clock->now());
    }

    #[Test]
    public function nowMicrosecondsReturnsPositiveInteger(): void
    {
        $clock = new SystemClock();

        self::assertGreaterThan(0, $clock->nowMicroseconds());
    }

    #[Test]
    public function nowMicrosecondsIsMonotonicAcrossTwoCalls(): void
    {
        $clock = new SystemClock();

        $first  = $clock->nowMicroseconds();
        $second = $clock->nowMicroseconds();

        self::assertGreaterThanOrEqual($first, $second);
    }
}
