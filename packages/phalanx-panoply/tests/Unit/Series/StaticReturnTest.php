<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Series;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Series;
use Phalanx\Panoply\Stream;
use PHPUnit\Framework\TestCase;

/**
 * Type-preserving combinators (where/take/skip/until/since/tee/merge/
 * interleave/interleaveBy) return `static`, so subclass identity is
 * preserved through chains. Type-changing combinators (map/pluck/chunk/
 * flatten/zip) return the base {@see Series}.
 */
final class StaticReturnTest extends TestCase
{
    public function test_stream_where_returns_stream(): void
    {
        $stream = self::stream();
        $filtered = $stream->where(static fn (Cue $c): bool => true);

        self::assertInstanceOf(Stream::class, $filtered);
        self::assertNotInstanceOf(Stream::class, Series::from([]));
    }

    public function test_stream_take_returns_stream(): void
    {
        self::assertInstanceOf(Stream::class, self::stream()->take(1));
    }

    public function test_stream_skip_returns_stream(): void
    {
        self::assertInstanceOf(Stream::class, self::stream()->skip(0));
    }

    public function test_stream_merge_returns_stream(): void
    {
        self::assertInstanceOf(Stream::class, self::stream()->merge(self::stream()));
    }

    public function test_stream_map_returns_base_series(): void
    {
        $mapped = self::stream()->map(static fn (Cue $c): string => $c->type);

        self::assertInstanceOf(Series::class, $mapped);
        self::assertNotInstanceOf(Stream::class, $mapped);
    }

    public function test_stream_chunk_returns_base_series(): void
    {
        $chunked = self::stream()->chunk(2);

        self::assertInstanceOf(Series::class, $chunked);
        self::assertNotInstanceOf(Stream::class, $chunked);
    }

    public function test_subclass_type_preserved_across_chained_combinators(): void
    {
        $chained = self::stream()
            ->where(static fn (Cue $c): bool => true)
            ->take(2)
            ->skip(0)
            ->merge(self::stream())
            ->tee(static fn (Cue $c): null => null);

        self::assertInstanceOf(Stream::class, $chained);
    }

    public function test_stream_from_returns_stream(): void
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
