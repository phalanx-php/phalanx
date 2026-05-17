<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Series;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Series;
use Phalanx\Panoply\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Type-preserving combinators (where/take/skip/until/since/tee/merge/
 * interleave/interleaveBy) return `static`, so subclass identity is
 * preserved through chains. Type-changing combinators (map/pluck/chunk/
 * flatten/zip) return the base {@see Series}.
 */
final class StaticReturnTest extends TestCase
{
    #[Test]
    public function streamWhereReturnsStream(): void
    {
        $stream = self::stream();
        $filtered = $stream->where(static fn (Cue $c): bool => true);

        self::assertInstanceOf(Stream::class, $filtered);
        self::assertNotInstanceOf(Stream::class, Series::from([]));
    }

    #[Test]
    public function streamTakeReturnsStream(): void
    {
        self::assertInstanceOf(Stream::class, self::stream()->take(1));
    }

    #[Test]
    public function streamSkipReturnsStream(): void
    {
        self::assertInstanceOf(Stream::class, self::stream()->skip(0));
    }

    #[Test]
    public function streamMergeReturnsStream(): void
    {
        self::assertInstanceOf(Stream::class, self::stream()->merge(self::stream()));
    }

    #[Test]
    public function streamMapReturnsBaseSeries(): void
    {
        $mapped = self::stream()->map(static fn (Cue $c): string => $c->type);

        self::assertInstanceOf(Series::class, $mapped);
        self::assertNotInstanceOf(Stream::class, $mapped);
    }

    #[Test]
    public function streamChunkReturnsBaseSeries(): void
    {
        $chunked = self::stream()->chunk(2);

        self::assertInstanceOf(Series::class, $chunked);
        self::assertNotInstanceOf(Stream::class, $chunked);
    }

    #[Test]
    public function subclassTypePreservedAcrossChainedCombinators(): void
    {
        $chained = self::stream()
            ->where(static fn (Cue $c): bool => true)
            ->take(2)
            ->skip(0)
            ->merge(self::stream())
            ->tee(static fn (Cue $c): null => null);

        self::assertInstanceOf(Stream::class, $chained);
    }

    #[Test]
    public function streamFromReturnsStream(): void
    {
        // Late-static-binding on `from()` preserves the subclass type.
        $stream = Stream::from([]);
        self::assertInstanceOf(Stream::class, $stream);
    }

    private static function stream(): Stream
    {
        return new Stream(static function (): \Generator {
            $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
            yield new TokenDelta('c1', 0, 'a1', 'i1', null, $at, text: 'h');
            yield new TokenDelta('c2', 1, 'a1', 'i1', null, $at, text: 'i');
        });
    }
}
