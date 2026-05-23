<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Series;

use Phalanx\Panoply\Artifact\Collection;
use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\HomeDir\Locators;
use Phalanx\Panoply\HomeDir\Projects;
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
        $filtered = $stream->where(static fn (Cue $_c): bool => true);

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
            ->where(static fn (Cue $_c): bool => true)
            ->take(2)
            ->skip(0)
            ->merge(self::stream())
            ->tee(static fn (Cue $_c): null => null);

        self::assertInstanceOf(Stream::class, $chained);
    }

    #[Test]
    public function streamFromReturnsStream(): void
    {
        // Late-static-binding on `from()` preserves the subclass type.
        $stream = Stream::from([]);
        self::assertInstanceOf(Stream::class, $stream);
    }

    // --- Conversation\Log ---

    #[Test]
    public function logWhereReturnsLog(): void
    {
        $log = Log::from([]);
        self::assertInstanceOf(Log::class, $log->where(static fn ($_r): bool => true));
    }

    #[Test]
    public function logMapReturnsBaseSeries(): void
    {
        $log = Log::from([]);
        $mapped = $log->map(static fn ($r): mixed => $r);
        self::assertInstanceOf(Series::class, $mapped);
        self::assertNotInstanceOf(Log::class, $mapped);
    }

    // --- Artifact\Collection ---

    #[Test]
    public function collectionWhereReturnsCollection(): void
    {
        $col = Collection::from([]);
        self::assertInstanceOf(Collection::class, $col->where(static fn ($_a): bool => true));
    }

    #[Test]
    public function collectionMapReturnsBaseSeries(): void
    {
        $col = Collection::from([]);
        $mapped = $col->map(static fn ($a): mixed => $a);
        self::assertInstanceOf(Series::class, $mapped);
        self::assertNotInstanceOf(Collection::class, $mapped);
    }

    // --- HomeDir\Projects ---

    #[Test]
    public function projectsWhereReturnsProjects(): void
    {
        $projects = Projects::from([]);
        self::assertInstanceOf(Projects::class, $projects->where(static fn ($_p): bool => true));
    }

    #[Test]
    public function projectsMapReturnsBaseSeries(): void
    {
        $projects = Projects::from([]);
        $mapped = $projects->map(static fn ($p): mixed => $p);
        self::assertInstanceOf(Series::class, $mapped);
        self::assertNotInstanceOf(Projects::class, $mapped);
    }

    // --- HomeDir\Locators ---

    #[Test]
    public function locatorsWhereReturnsLocators(): void
    {
        $locators = Locators::from([]);
        self::assertInstanceOf(Locators::class, $locators->where(static fn ($_l): bool => true));
    }

    #[Test]
    public function locatorsMapReturnsBaseSeries(): void
    {
        $locators = Locators::from([]);
        $mapped = $locators->map(static fn ($l): mixed => $l);
        self::assertInstanceOf(Series::class, $mapped);
        self::assertNotInstanceOf(Locators::class, $mapped);
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
