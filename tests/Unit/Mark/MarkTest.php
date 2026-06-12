<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Mark;

use InvalidArgumentException;
use Phalanx\Mark\Mark;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkTest extends TestCase
{
    #[Test]
    public function durationFactoriesScaleToNanoseconds(): void
    {
        self::assertSame(2 * 3_600_000_000_000, Mark::h(2)->toNanoseconds());
        self::assertSame(5 * 60_000_000_000, Mark::m(5)->toNanoseconds());
        self::assertSame(3_000_000_000, Mark::s(3)->toNanoseconds());
        self::assertSame(1_500_000_000, Mark::s(1.5)->toNanoseconds());
        self::assertSame(250_000_000, Mark::ms(250)->toNanoseconds());
        self::assertSame(7_000, Mark::us(7)->toNanoseconds());
        self::assertSame(42, Mark::ns(42)->toNanoseconds());
        self::assertTrue(Mark::zero()->isZero());
    }

    #[Test]
    public function arithmeticIsSaturatingOnSubtractionAndCheckedOnAddition(): void
    {
        self::assertSame(0, Mark::ms(1)->minus(Mark::s(1))->toNanoseconds());
        self::assertSame(1_250_000_000, Mark::s(1)->plus(Mark::ms(250))->toNanoseconds());

        $this->expectException(InvalidArgumentException::class);

        Mark::ns(PHP_INT_MAX)->plus(Mark::ns(1));
    }

    #[Test]
    public function comparisonsAreNanosecondExact(): void
    {
        self::assertTrue(Mark::s(2)->gt(Mark::ms(1_999)));
        self::assertTrue(Mark::ms(1)->lt(Mark::ms(2)));
        self::assertTrue(Mark::s(1)->eq(Mark::ms(1_000)));
        self::assertTrue(Mark::s(1)->gte(Mark::s(1)));
        self::assertTrue(Mark::s(1)->lte(Mark::s(1)));
        self::assertSame(Mark::s(2)->toNanoseconds(), Mark::s(1)->max(Mark::s(2))->toNanoseconds());
        self::assertSame(Mark::s(1)->toNanoseconds(), Mark::s(1)->min(Mark::s(2))->toNanoseconds());
    }

    #[Test]
    public function instantsMeasureElapsedTime(): void
    {
        $start = Mark::now();

        self::assertTrue($start->isPositive());
        self::assertSame(500_000_000, $start->until($start->plus(Mark::ms(500)))->toNanoseconds());
        self::assertSame(0, $start->since($start->plus(Mark::s(1)))->toNanoseconds());
    }

    #[Test]
    public function negativeDurationsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mark::s(-1);
    }
}
