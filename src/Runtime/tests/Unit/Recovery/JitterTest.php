<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Tests\Unit\Recovery;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\Jitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JitterTest extends TestCase
{
    #[Test]
    public function noneReturnsInputUnchanged(): void
    {
        $jitter = Jitter::none();

        $delay = Mark::ms(100);
        $result = $jitter->apply($delay);

        self::assertTrue($result->eq($delay));
    }

    #[Test]
    public function percentAddsProportionalJitter(): void
    {
        $jitter = Jitter::percent(10, static fn(): float => 1.0);

        $result = $jitter->apply(Mark::ms(100));

        self::assertSame(110, $result->toMilliseconds());
    }

    #[Test]
    public function percentWithZeroRandomAddsNothing(): void
    {
        $jitter = Jitter::percent(10, static fn(): float => 0.0);

        $result = $jitter->apply(Mark::ms(100));

        self::assertSame(100, $result->toMilliseconds());
    }

    #[Test]
    public function percentWithHalfRandomAddsHalf(): void
    {
        $jitter = Jitter::percent(20, static fn(): float => 0.5);

        $result = $jitter->apply(Mark::ms(100));

        self::assertSame(110, $result->toMilliseconds());
    }

    #[Test]
    public function rangeAddsWithinBounds(): void
    {
        $jitter = Jitter::range(Mark::ms(10), Mark::ms(50), static fn(): float => 0.5);

        $result = $jitter->apply(Mark::ms(100));

        self::assertSame(130, $result->toMilliseconds());
    }

    #[Test]
    public function rangeMinBound(): void
    {
        $jitter = Jitter::range(Mark::ms(10), Mark::ms(50), static fn(): float => 0.0);

        $result = $jitter->apply(Mark::ms(100));

        self::assertSame(110, $result->toMilliseconds());
    }

    #[Test]
    public function rangeMaxBound(): void
    {
        $jitter = Jitter::range(Mark::ms(10), Mark::ms(50), static fn(): float => 1.0);

        $result = $jitter->apply(Mark::ms(100));

        self::assertSame(150, $result->toMilliseconds());
    }
}
