<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Series;

use Phalanx\Panoply\Series;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Series::class)]
final class CombinatorTest extends TestCase
{
    public function test_where(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])
            ->where(static fn (int $n): bool => $n > 2)
            ->toArray();

        self::assertSame([3, 4, 5], $r);
    }

    public function test_map(): void
    {
        $r = Series::from([1, 2, 3])
            ->map(static fn (int $n): int => $n * 2)
            ->toArray();

        self::assertSame([2, 4, 6], $r);
    }

    public function test_take(): void
    {
        $r = Series::from(range(1, 10))->take(3)->toArray();
        self::assertSame([1, 2, 3], $r);
    }

    public function test_skip(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])->skip(2)->toArray();
        self::assertSame([3, 4, 5], $r);
    }

    public function test_until_is_exclusive(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])
            ->until(static fn (int $n): bool => $n >= 3)
            ->toArray();

        self::assertSame([1, 2], $r);
    }

    public function test_since_is_inclusive(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])
            ->since(static fn (int $n): bool => $n >= 3)
            ->toArray();

        self::assertSame([3, 4, 5], $r);
    }

    public function test_tee_observes_without_consuming(): void
    {
        $observed = [];
        $r = Series::from([1, 2, 3])
            ->tee(static function (int $n) use (&$observed): void {
                $observed[] = $n;
            })
            ->toArray();

        self::assertSame([1, 2, 3], $r);
        self::assertSame([1, 2, 3], $observed);
    }

    public function test_pluck_property(): void
    {
        $items = [
            (object) ['name' => 'a'],
            (object) ['name' => 'b'],
        ];

        $r = Series::from($items)->pluck('name')->toArray();
        self::assertSame(['a', 'b'], $r);
    }

    public function test_pluck_array_key(): void
    {
        $items = [['k' => 1], ['k' => 2], ['k' => 3]];
        $r = Series::from($items)->pluck('k')->toArray();
        self::assertSame([1, 2, 3], $r);
    }

    public function test_chunk(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])->chunk(2)->toArray();
        self::assertSame([[1, 2], [3, 4], [5]], $r);
    }

    public function test_flatten(): void
    {
        $r = Series::from([[1, 2], [3], [], [4, 5]])->flatten()->toArray();
        self::assertSame([1, 2, 3, 4, 5], $r);
    }

    public function test_zip(): void
    {
        $r = Series::from([1, 2, 3])
            ->zip(Series::from(['a', 'b', 'c']), Series::from(['x', 'y', 'z']))
            ->toArray();

        self::assertSame(
            [[1, 'a', 'x'], [2, 'b', 'y'], [3, 'c', 'z']],
            $r,
        );
    }

    public function test_zip_stops_at_shortest(): void
    {
        $r = Series::from([1, 2, 3])->zip(Series::from(['a', 'b']))->toArray();
        self::assertSame([[1, 'a'], [2, 'b']], $r);
    }

    public function test_merge_concatenates(): void
    {
        $r = Series::from([1, 2])
            ->merge(Series::from([3, 4]), Series::from([5]))
            ->toArray();

        self::assertSame([1, 2, 3, 4, 5], $r);
    }

    public function test_interleave_round_robin(): void
    {
        $r = Series::from([1, 2, 3])
            ->interleave(Series::from([10, 20, 30]), Series::from([100, 200]))
            ->toArray();

        self::assertSame([1, 10, 100, 2, 20, 200, 3, 30], $r);
    }

    public function test_interleave_by_sort_merges_by_key(): void
    {
        $a = Series::from([['t' => 1], ['t' => 4], ['t' => 7]]);
        $b = Series::from([['t' => 2], ['t' => 5], ['t' => 8]]);
        $c = Series::from([['t' => 3], ['t' => 6]]);

        $r = $a->interleaveBy(static fn (array $row): int => $row['t'], $b, $c)
            ->toArray();

        $timestamps = array_map(static fn (array $row): int => $row['t'], $r);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], $timestamps);
    }

    public function test_interleave_with_no_others_is_identity(): void
    {
        $r = Series::from([1, 2, 3])->interleave()->toArray();
        self::assertSame([1, 2, 3], $r);
    }

    public function test_interleave_with_empty_primary(): void
    {
        $r = Series::from([])
            ->interleave(Series::from([1, 2, 3]))
            ->toArray();

        self::assertSame([1, 2, 3], $r);
    }

    public function test_interleave_all_empty(): void
    {
        $r = Series::from([])->interleave(Series::from([]), Series::from([]))->toArray();
        self::assertSame([], $r);
    }

    public function test_interleave_by_ties_break_in_declared_order(): void
    {
        $a = Series::from([['t' => 1, 'src' => 'a'], ['t' => 2, 'src' => 'a']]);
        $b = Series::from([['t' => 1, 'src' => 'b'], ['t' => 2, 'src' => 'b']]);

        $r = $a->interleaveBy(static fn (array $row): int => $row['t'], $b)
            ->toArray();

        $sources = array_map(static fn (array $row): string => $row['src'], $r);
        self::assertSame(['a', 'b', 'a', 'b'], $sources);
    }

    public function test_interleave_by_with_no_others_yields_sorted_input(): void
    {
        $r = Series::from([['t' => 1], ['t' => 2], ['t' => 3]])
            ->interleaveBy(static fn (array $row): int => $row['t'])
            ->toArray();

        $timestamps = array_map(static fn (array $row): int => $row['t'], $r);
        self::assertSame([1, 2, 3], $timestamps);
    }

    public function test_interleave_by_dedup_drops_duplicate_digests(): void
    {
        $a = Series::from([['t' => 1, 'h' => 'aaa'], ['t' => 3, 'h' => 'ccc']]);
        $b = Series::from([['t' => 2, 'h' => 'bbb'], ['t' => 3, 'h' => 'ccc']]);

        $r = $a->interleaveByDedup(
            static fn (array $row): int => $row['t'],
            static fn (array $row): string => $row['h'],
            $b,
        )->toArray();

        $hashes = array_map(static fn (array $row): string => $row['h'], $r);
        self::assertSame(['aaa', 'bbb', 'ccc'], $hashes, 'duplicate ccc appears once');
    }

    public function test_reduce(): void
    {
        $sum = Series::from([1, 2, 3, 4])->reduce(
            static fn (int $acc, int $n): int => $acc + $n,
            0,
        );

        self::assertSame(10, $sum);
    }

    public function test_each(): void
    {
        $seen = [];
        Series::from([1, 2, 3])->each(static function (int $n) use (&$seen): void {
            $seen[] = $n;
        });

        self::assertSame([1, 2, 3], $seen);
    }

    public function test_first_returns_null_on_empty(): void
    {
        self::assertNull(Series::from([])->first());
    }

    public function test_last(): void
    {
        self::assertSame(3, Series::from([1, 2, 3])->last());
        self::assertNull(Series::from([])->last());
    }

    public function test_count(): void
    {
        self::assertSame(0, Series::from([])->count());
        self::assertSame(5, Series::from([1, 2, 3, 4, 5])->count());
    }

    public function test_to_array(): void
    {
        self::assertSame([1, 2, 3], Series::from([1, 2, 3])->toArray());
    }

    public function test_take_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Series::from([1, 2, 3])->take(-1);
    }

    public function test_skip_rejects_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Series::from([1, 2, 3])->skip(-1);
    }

    public function test_chunk_rejects_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Series::from([1, 2, 3])->chunk(0);
    }
}
