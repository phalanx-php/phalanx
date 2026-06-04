<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Clock;

use Phalanx\Panoply\Clock\FrozenClock;
use Phalanx\Mark\Mark;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    #[Test]
    public function nowMicrosecondsReturnsConstructedValue(): void
    {
        $clock = new FrozenClock(1_000_000);

        self::assertSame(1_000_000, $clock->nowMicroseconds());
    }

    #[Test]
    public function advanceIncreasesMicroseconds(): void
    {
        $clock = new FrozenClock(0);

        $clock->advance(Mark::ms(100));

        self::assertSame(100_000, $clock->nowMicroseconds());
    }

    #[Test]
    public function multipleAdvancesAccumulate(): void
    {
        $clock = new FrozenClock(0);

        $clock->advance(Mark::ms(50));
        $clock->advance(Mark::ms(50));

        self::assertSame(100_000, $clock->nowMicroseconds());
    }

    #[Test]
    public function setOverridesCurrentValue(): void
    {
        $clock = new FrozenClock(5_000_000);

        $clock->set(1_000);

        self::assertSame(1_000, $clock->nowMicroseconds());
    }

    #[Test]
    public function nowReturnsDateTimeImmutable(): void
    {
        $clock = new FrozenClock(1_000_000);

        self::assertInstanceOf(\DateTimeImmutable::class, $clock->now());
    }

    #[Test]
    public function nowReflectsAdvances(): void
    {
        $clock = new FrozenClock(0);

        $t1 = $clock->now()->getTimestamp();
        $clock->advance(Mark::s(10));
        $t2 = $clock->now()->getTimestamp();

        self::assertSame(10, $t2 - $t1);
    }

    #[Test]
    public function constructWithZeroMicrosecondsIsValid(): void
    {
        $clock = new FrozenClock(0);

        self::assertSame(0, $clock->nowMicroseconds());
    }
}
