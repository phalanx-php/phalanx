<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Series;

use Phalanx\Panoply\Series;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CombinatorTest extends TestCase
{
    #[Test]
    public function whereFiltersByPredicate(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])
            ->where(static fn (int $n): bool => $n > 2)
            ->toArray();

        self::assertSame([3, 4, 5], $r);
    }

    #[Test]
    public function mapTransformsEachItem(): void
    {
        $r = Series::from([1, 2, 3])
            ->map(static fn (int $n): int => $n * 2)
            ->toArray();

        self::assertSame([2, 4, 6], $r);
    }

    #[Test]
    public function takeYieldsFirstN(): void
    {
        $r = Series::from(range(1, 10))->take(3)->toArray();
        self::assertSame([1, 2, 3], $r);
    }

    #[Test]
    public function skipDropsFirstN(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])->skip(2)->toArray();
        self::assertSame([3, 4, 5], $r);
    }

    #[Test]
    public function untilIsExclusive(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])
            ->until(static fn (int $n): bool => $n >= 3)
            ->toArray();

        self::assertSame([1, 2], $r);
    }

    #[Test]
    public function sinceIsInclusive(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])
            ->since(static fn (int $n): bool => $n >= 3)
            ->toArray();

        self::assertSame([3, 4, 5], $r);
    }

    #[Test]
    public function teeObservesWithoutConsuming(): void
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

    #[Test]
    public function pluckProperty(): void
    {
        $items = [
            (object) ['name' => 'a'],
            (object) ['name' => 'b'],
        ];

        $r = Series::from($items)->pluck('name')->toArray();
        self::assertSame(['a', 'b'], $r);
    }

    #[Test]
    public function pluckArrayKey(): void
    {
        $items = [['k' => 1], ['k' => 2], ['k' => 3]];
        $r = Series::from($items)->pluck('k')->toArray();
        self::assertSame([1, 2, 3], $r);
    }

    #[Test]
    public function chunkGroupsIntoFixedSize(): void
    {
        $r = Series::from([1, 2, 3, 4, 5])->chunk(2)->toArray();
        self::assertSame([[1, 2], [3, 4], [5]], $r);
    }

    #[Test]
    public function flattenInlinesNestedIterables(): void
    {
        $r = Series::from([[1, 2], [3], [], [4, 5]])->flatten()->toArray();
        self::assertSame([1, 2, 3, 4, 5], $r);
    }

    #[Test]
    public function zipPairsAlignedItems(): void
    {
        $r = Series::from([1, 2, 3])
            ->zip(Series::from(['a', 'b', 'c']), Series::from(['x', 'y', 'z']))
            ->toArray();

        self::assertSame(
            [[1, 'a', 'x'], [2, 'b', 'y'], [3, 'c', 'z']],
            $r,
        );
    }

    #[Test]
    public function zipStopsAtShortest(): void
    {
        $r = Series::from([1, 2, 3])->zip(Series::from(['a', 'b']))->toArray();
        self::assertSame([[1, 'a'], [2, 'b']], $r);
    }

    #[Test]
    public function mergeConcatenates(): void
    {
        $r = Series::from([1, 2])
            ->merge(Series::from([3, 4]), Series::from([5]))
            ->toArray();

        self::assertSame([1, 2, 3, 4, 5], $r);
    }

    #[Test]
    public function interleaveRoundRobin(): void
    {
        $r = Series::from([1, 2, 3])
            ->interleave(Series::from([10, 20, 30]), Series::from([100, 200]))
            ->toArray();

        self::assertSame([1, 10, 100, 2, 20, 200, 3, 30], $r);
    }

    #[Test]
    public function interleaveBySortMergesByKey(): void
    {
        $a = Series::from([['t' => 1], ['t' => 4], ['t' => 7]]);
        $b = Series::from([['t' => 2], ['t' => 5], ['t' => 8]]);
        $c = Series::from([['t' => 3], ['t' => 6]]);

        $r = $a->interleaveBy(static fn (array $row): int => $row['t'], $b, $c)
            ->toArray();

        $timestamps = array_map(static fn (array $row): int => $row['t'], $r);
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8], $timestamps);
    }

    #[Test]
    public function interleaveWithNoOthersIsIdentity(): void
    {
        $r = Series::from([1, 2, 3])->interleave()->toArray();
        self::assertSame([1, 2, 3], $r);
    }

    #[Test]
    public function interleaveWithEmptyPrimary(): void
    {
        $r = Series::from([])
            ->interleave(Series::from([1, 2, 3]))
            ->toArray();

        self::assertSame([1, 2, 3], $r);
    }

    #[Test]
    public function interleaveAllEmpty(): void
    {
        $r = Series::from([])->interleave(Series::from([]), Series::from([]))->toArray();
        self::assertSame([], $r);
    }

    #[Test]
    public function interleaveByTiesBreakInDeclaredOrder(): void
    {
        $a = Series::from([['t' => 1, 'src' => 'a'], ['t' => 2, 'src' => 'a']]);
        $b = Series::from([['t' => 1, 'src' => 'b'], ['t' => 2, 'src' => 'b']]);

        $r = $a->interleaveBy(static fn (array $row): int => $row['t'], $b)
            ->toArray();

        $sources = array_map(static fn (array $row): string => $row['src'], $r);
        self::assertSame(['a', 'b', 'a', 'b'], $sources);
    }

    #[Test]
    public function interleaveByWithNoOthersYieldsSortedInput(): void
    {
        $r = Series::from([['t' => 1], ['t' => 2], ['t' => 3]])
            ->interleaveBy(static fn (array $row): int => $row['t'])
            ->toArray();

        $timestamps = array_map(static fn (array $row): int => $row['t'], $r);
        self::assertSame([1, 2, 3], $timestamps);
    }

    #[Test]
    public function interleaveByDedupDropsDuplicateDigests(): void
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

    #[Test]
    public function reduceFoldsToSingleValue(): void
    {
        $sum = Series::from([1, 2, 3, 4])->reduce(
            static fn (int $acc, int $n): int => $acc + $n,
            0,
        );

        self::assertSame(10, $sum);
    }

    #[Test]
    public function eachVisitsEveryItem(): void
    {
        $seen = [];
        Series::from([1, 2, 3])->each(static function (int $n) use (&$seen): void {
            $seen[] = $n;
        });

        self::assertSame([1, 2, 3], $seen);
    }

    #[Test]
    public function firstReturnsNullOnEmpty(): void
    {
        self::assertNull(Series::from([])->first());
    }

    #[Test]
    public function lastReturnsFinalOrNull(): void
    {
        self::assertSame(3, Series::from([1, 2, 3])->last());
        self::assertNull(Series::from([])->last());
    }

    #[Test]
    public function countMaterializesAll(): void
    {
        self::assertSame(0, Series::from([])->count());
        self::assertSame(5, Series::from([1, 2, 3, 4, 5])->count());
    }

    #[Test]
    public function toArrayMaterializesItems(): void
    {
        self::assertSame([1, 2, 3], Series::from([1, 2, 3])->toArray());
    }

    #[Test]
    public function takeRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Series::from([1, 2, 3])->take(-1);
    }

    #[Test]
    public function skipRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Series::from([1, 2, 3])->skip(-1);
    }

    #[Test]
    public function chunkRejectsZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Series::from([1, 2, 3])->chunk(0);
    }

    #[Test]
    public function whereOnEmptyYieldsEmpty(): void
    {
        self::assertSame([], Series::from([])->where(static fn ($x): bool => true)->toArray());
    }

    #[Test]
    public function mapOnEmptyYieldsEmpty(): void
    {
        self::assertSame([], Series::from([])->map(static fn ($x): mixed => $x)->toArray());
    }

    #[Test]
    public function takeOnEmptyYieldsEmpty(): void
    {
        self::assertSame([], Series::from([])->take(5)->toArray());
    }

    #[Test]
    public function chunkOnEmptyYieldsEmpty(): void
    {
        self::assertSame([], Series::from([])->chunk(2)->toArray());
    }

    #[Test]
    public function reduceOnEmptyReturnsInitial(): void
    {
        self::assertSame(0, Series::from([])->reduce(static fn (int $a, int $x): int => $a + $x, 0));
    }
}
