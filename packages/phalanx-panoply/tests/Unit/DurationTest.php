<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Duration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    #[Test]
    public function msFactoryStoresMillisecondsAsMicroseconds(): void
    {
        $d = Duration::ms(100);

        self::assertSame(100_000, $d->microseconds);
    }

    #[Test]
    public function secondsFactoryStoresSecondsAsMicroseconds(): void
    {
        $d = Duration::seconds(2);

        self::assertSame(2_000_000, $d->microseconds);
    }

    #[Test]
    public function microsecondsFactoryStoresMicrosecondsDirectly(): void
    {
        $d = Duration::microseconds(500);

        self::assertSame(500, $d->microseconds);
    }

    #[Test]
    public function zeroIsAccepted(): void
    {
        $d = Duration::ms(0);

        self::assertSame(0, $d->microseconds);
    }

    #[Test]
    public function toMillisecondsReturnsMilliseconds(): void
    {
        $d = Duration::ms(250);

        self::assertSame(250, $d->toMilliseconds());
    }

    #[Test]
    public function toSecondsReturnsFloat(): void
    {
        $d = Duration::ms(500);

        self::assertEqualsWithDelta(0.5, $d->toSeconds(), 0.000001);
    }

    #[Test]
    public function toMicrosecondsReturnsMicroseconds(): void
    {
        $d = Duration::ms(100);

        self::assertSame(100_000, $d->toMicroseconds());
    }

    #[Test]
    public function toMillisecondsRoundsDown(): void
    {
        // 1500 µs → 1 ms (integer division truncates)
        $d = Duration::microseconds(1_500);

        self::assertSame(1, $d->toMilliseconds());
    }

    #[Test]
    public function negativeInputThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Duration::ms(-1);
    }

    #[Test]
    public function negativeMicrosecondsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Duration::microseconds(-1);
    }

    #[Test]
    public function negativeSecondsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Duration::seconds(-5);
    }
}
