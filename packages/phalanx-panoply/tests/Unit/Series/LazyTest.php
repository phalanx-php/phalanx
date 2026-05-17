<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Series;

use Phalanx\Panoply\Series;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Series::class)]
final class LazyTest extends TestCase
{
    public function test_combinators_do_not_consume_until_iterated(): void
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

    public function test_take_yields_at_most_n(): void
    {
        $series = Series::from(range(1, 100));
        self::assertCount(7, $series->take(7)->toArray());
    }

    public function test_take_zero_yields_nothing(): void
    {
        $series = Series::from(range(1, 100));
        self::assertSame([], $series->take(0)->toArray());
    }

    public function test_take_more_than_available_yields_all(): void
    {
        $series = Series::from([1, 2, 3]);
        self::assertSame([1, 2, 3], $series->take(99)->toArray());
    }

    public function test_first_consumes_only_one_item(): void
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

    public function test_series_can_be_iterated_more_than_once(): void
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
