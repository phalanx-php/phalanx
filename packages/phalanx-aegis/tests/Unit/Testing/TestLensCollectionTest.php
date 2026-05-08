<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Testing;

use Phalanx\Testing\Lenses\LedgerLens;
use Phalanx\Testing\Lenses\RuntimeLens;
use Phalanx\Testing\Lenses\ScopeLens;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Testing\TestLens;
use PHPUnit\Framework\Attributes\Test;

final class TestLensCollectionTest extends PhalanxTestCase
{
    #[Test]
    public function of_preserves_lens_class_strings_in_declaration_order(): void
    {
        $collection = TestLens::of(LedgerLens::class, ScopeLens::class);

        self::assertSame([LedgerLens::class, ScopeLens::class], $collection->all());
        self::assertFalse($collection->isEmpty());
    }

    #[Test]
    public function none_produces_an_empty_collection(): void
    {
        $collection = TestLens::none();

        self::assertSame([], $collection->all());
        self::assertTrue($collection->isEmpty());
    }

    #[Test]
    public function merge_deduplicates_overlapping_entries_keeping_first_occurrence(): void
    {
        $a = TestLens::of(LedgerLens::class, ScopeLens::class);
        $b = TestLens::of(ScopeLens::class, RuntimeLens::class);

        $merged = $a->merge($b);

        self::assertSame(
            [LedgerLens::class, ScopeLens::class, RuntimeLens::class],
            $merged->all(),
        );
    }

    #[Test]
    public function merging_none_onto_a_populated_collection_returns_the_original(): void
    {
        $populated = TestLens::of(LedgerLens::class, ScopeLens::class);
        $none = TestLens::none();

        self::assertSame($populated, $populated->merge($none));
    }

    #[Test]
    public function merging_a_populated_collection_onto_none_returns_the_other(): void
    {
        $none = TestLens::none();
        $populated = TestLens::of(LedgerLens::class, ScopeLens::class);

        self::assertSame($populated, $none->merge($populated));
    }
}
