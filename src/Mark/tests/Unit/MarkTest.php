<?php

declare(strict_types=1);

namespace Phalanx\Mark\Tests\Unit;

use InvalidArgumentException;
use Phalanx\Mark\Mark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarkTest extends TestCase
{
    #[Test]
    public function nsFactoryStoresDirectly(): void
    {
        $m = Mark::ns(500);

        self::assertSame(500, $m->nanoseconds);
    }

    #[Test]
    public function usFactoryConvertsToNanoseconds(): void
    {
        $m = Mark::us(100);

        self::assertSame(100_000, $m->nanoseconds);
    }

    #[Test]
    public function msIntFactoryConvertsToNanoseconds(): void
    {
        $m = Mark::ms(100);

        self::assertSame(100_000_000, $m->nanoseconds);
    }

    #[Test]
    public function msFloatFactoryHandlesFractional(): void
    {
        $m = Mark::ms(1.5);

        self::assertSame(1_500_000, $m->nanoseconds);
    }

    #[Test]
    public function sIntFactoryConvertsToNanoseconds(): void
    {
        $m = Mark::s(2);

        self::assertSame(2_000_000_000, $m->nanoseconds);
    }

    #[Test]
    public function sFloatFactoryHandlesFractional(): void
    {
        $m = Mark::s(0.5);

        self::assertSame(500_000_000, $m->nanoseconds);
    }

    #[Test]
    public function zeroFactoryIsZero(): void
    {
        $m = Mark::zero();

        self::assertTrue($m->isZero());
        self::assertSame(0, $m->nanoseconds);
    }

    #[Test]
    public function zeroNsIsAccepted(): void
    {
        self::assertSame(0, Mark::ns(0)->nanoseconds);
    }

    #[Test]
    public function toMillisecondsReturnsMilliseconds(): void
    {
        self::assertSame(250, Mark::ms(250)->toMilliseconds());
    }

    #[Test]
    public function toSecondsReturnsFloat(): void
    {
        self::assertEqualsWithDelta(0.5, Mark::ms(500)->toSeconds(), 0.000001);
    }

    #[Test]
    public function toMicrosecondsReturnsMicroseconds(): void
    {
        self::assertSame(100, Mark::us(100)->toMicroseconds());
    }

    #[Test]
    public function toNanosecondsReturnsNanoseconds(): void
    {
        self::assertSame(42, Mark::ns(42)->toNanoseconds());
    }

    #[Test]
    public function toMillisecondsRoundsDown(): void
    {
        $m = Mark::us(1_500);

        self::assertSame(1, $m->toMilliseconds());
    }

    #[Test]
    public function toSwooleMsClampsToOne(): void
    {
        self::assertSame(1, Mark::ns(500)->toSwooleMs());
        self::assertSame(1, Mark::zero()->toSwooleMs());
    }

    #[Test]
    public function toSwooleMsReturnsActualValueAboveOne(): void
    {
        self::assertSame(50, Mark::ms(50)->toSwooleMs());
    }

    #[Test]
    public function toMillisecondsDoesNotClamp(): void
    {
        self::assertSame(0, Mark::zero()->toMilliseconds());
    }

    #[Test]
    public function negativeNsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mark::ns(-1);
    }

    #[Test]
    public function negativeUsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mark::us(-1);
    }

    #[Test]
    public function negativeMsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mark::ms(-1);
    }

    #[Test]
    public function negativeSThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mark::s(-1);
    }

    #[Test]
    public function negativeMsFloatThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mark::ms(-0.5);
    }

    #[Test]
    public function msOverflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        Mark::ms(PHP_INT_MAX);
    }

    #[Test]
    public function sOverflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        Mark::s(PHP_INT_MAX);
    }

    #[Test]
    public function usOverflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        Mark::us(PHP_INT_MAX);
    }

    #[Test]
    public function sFloatOverflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        Mark::s(9.3e9);
    }

    #[Test]
    public function msFloatOverflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        Mark::ms(9.3e12);
    }

    #[Test]
    public function fromMicrotimeOverflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        Mark::fromMicrotime(9.3e9);
    }

    #[Test]
    public function plusOverflowThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/overflow/i');

        $half = Mark::ns((int) (PHP_INT_MAX * 0.6));

        $half->plus($half);
    }

    #[Test]
    public function fromMicrotimeZeroIsValid(): void
    {
        $m = Mark::fromMicrotime(0.0);

        self::assertTrue($m->isZero());
    }

    #[Test]
    public function negativeInputIncludesCallerValue(): void
    {
        $caught = null;

        try {
            Mark::ms(-42);
        } catch (InvalidArgumentException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('-42', $caught->getMessage());
    }

    #[Test]
    public function plusAdds(): void
    {
        $result = Mark::ms(100)->plus(Mark::ms(50));

        self::assertSame(150, $result->toMilliseconds());
    }

    #[Test]
    public function minusClampsToZero(): void
    {
        $result = Mark::ms(50)->minus(Mark::ms(100));

        self::assertTrue($result->isZero());
    }

    #[Test]
    public function minusSubtracts(): void
    {
        $result = Mark::ms(100)->minus(Mark::ms(30));

        self::assertSame(70, $result->toMilliseconds());
    }

    #[Test]
    public function maxReturnsLarger(): void
    {
        self::assertSame(200, Mark::ms(100)->max(Mark::ms(200))->toMilliseconds());
        self::assertSame(200, Mark::ms(200)->max(Mark::ms(100))->toMilliseconds());
    }

    #[Test]
    public function minReturnsSmaller(): void
    {
        self::assertSame(100, Mark::ms(100)->min(Mark::ms(200))->toMilliseconds());
        self::assertSame(100, Mark::ms(200)->min(Mark::ms(100))->toMilliseconds());
    }

    #[Test]
    public function isPositiveOnNonZero(): void
    {
        self::assertTrue(Mark::ns(1)->isPositive());
    }

    #[Test]
    public function isPositiveOnZero(): void
    {
        self::assertFalse(Mark::zero()->isPositive());
    }

    #[Test]
    #[DataProvider('comparisonPairs')]
    public function comparisons(Mark $a, Mark $b, bool $gt, bool $lt, bool $eq): void
    {
        self::assertSame($gt, $a->gt($b));
        self::assertSame($lt, $a->lt($b));
        self::assertSame($eq, $a->eq($b));
        self::assertSame($gt || $eq, $a->gte($b));
        self::assertSame($lt || $eq, $a->lte($b));
    }

    /** @return iterable<string, array{Mark, Mark, bool, bool, bool}> */
    public static function comparisonPairs(): iterable
    {
        yield 'greater' => [Mark::ms(200), Mark::ms(100), true, false, false];
        yield 'lesser' => [Mark::ms(100), Mark::ms(200), false, true, false];
        yield 'equal' => [Mark::ms(100), Mark::ms(100), false, false, true];
        yield 'zero-equal' => [Mark::zero(), Mark::ns(0), false, false, true];
    }

    #[Test]
    public function nowReturnsPositive(): void
    {
        self::assertTrue(Mark::now()->isPositive());
    }

    #[Test]
    public function nowIsMonotonic(): void
    {
        $a = Mark::now();
        $b = Mark::now();

        self::assertTrue($b->gte($a));
    }

    #[Test]
    public function elapsedReturnsNonNegative(): void
    {
        $start = Mark::now();

        $elapsed = $start->elapsed();

        self::assertFalse($elapsed->lt(Mark::zero()));
    }

    #[Test]
    public function sinceAndUntilAreSymmetric(): void
    {
        $a = Mark::ns(1000);
        $b = Mark::ns(3000);

        self::assertTrue($b->since($a)->eq($a->until($b)));
    }

    #[Test]
    public function fromMicrotimeConverts(): void
    {
        $m = Mark::fromMicrotime(1.5);

        self::assertSame(1_500_000_000, $m->nanoseconds);
    }

    #[Test]
    public function fromMicrotimeRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Mark::fromMicrotime(-1.0);
    }
}
