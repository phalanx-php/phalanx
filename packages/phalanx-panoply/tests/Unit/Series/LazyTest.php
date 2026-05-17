<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Series;

use Phalanx\Panoply\Series;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LazyTest extends TestCase
{
    #[Test]
    public function combinatorsDoNotConsumeUntilIterated(): void
    {
        $consumed = 0;
        $source = static function () use (&$consumed): \Generator {
            for ($i = 0; $i < 1000; $i++) {
                $consumed++;
                yield $i;
            }
        };

        $series = new Series($source);
        $derived = $series->where(static fn (int $n): bool => $n % 2 === 0)
            ->map(static fn (int $n): int => $n * 10)
            ->take(3);

        self::assertSame(0, $consumed, 'no consumption before iteration');

        $values = $derived->toArray();

        self::assertSame([0, 20, 40], $values);
        self::assertLessThanOrEqual(6, $consumed, 'only the items needed to yield 3 evens should be consumed');
    }

    #[Test]
    public function takeYieldsAtMostN(): void
    {
        $series = Series::from(range(1, 100));
        self::assertCount(7, $series->take(7)->toArray());
    }

    #[Test]
    public function takeZeroYieldsNothing(): void
    {
        $series = Series::from(range(1, 100));
        self::assertSame([], $series->take(0)->toArray());
    }

    #[Test]
    public function takeMoreThanAvailableYieldsAll(): void
    {
        $series = Series::from([1, 2, 3]);
        self::assertSame([1, 2, 3], $series->take(99)->toArray());
    }

    #[Test]
    public function firstConsumesOnlyOneItem(): void
    {
        $consumed = 0;
        $source = static function () use (&$consumed): \Generator {
            for ($i = 0; $i < 1000; $i++) {
                $consumed++;
                yield $i;
            }
        };

        $first = new Series($source)->first();

        self::assertSame(0, $first);
        self::assertSame(1, $consumed);
    }

    #[Test]
    public function seriesCanBeIteratedMoreThanOnce(): void
    {
        // The source factory closure is stored; each getIterator() call
        // invokes a fresh Generator, so a Series is safe to iterate
        // repeatedly without rebuilding.
        $s = Series::from([1, 2, 3]);

        self::assertSame([1, 2, 3], $s->toArray());
        self::assertSame([1, 2, 3], $s->toArray());
        self::assertSame(3, $s->count());
    }
}
