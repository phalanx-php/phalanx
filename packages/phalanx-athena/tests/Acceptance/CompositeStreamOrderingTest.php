<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Acceptance;

use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompositeStreamOrderingTest extends TestCase
{
    #[Test]
    public function hostCuesInterleavedWithProviderCuesProduceMonotonicallyOrderedSequence(): void
    {
        $scope = new ScopeStub();
        $at    = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $providerCues = [
            new TokenDelta('cue_p1', 2, 'act_order', null, null, $at, 'provider-2'),
            new TokenDelta('cue_p3', 4, 'act_order', null, null, $at, 'provider-4'),
            new TokenDelta('cue_p5', 6, 'act_order', null, null, $at, 'provider-6'),
        ];

        $stream   = CompositeStream::wrap($scope, Stream::from($providerCues));

        $stream->emit(new TokenDelta('cue_h1', 1, 'act_order', null, null, $at, 'host-1'));
        $stream->emit(new TokenDelta('cue_h3', 3, 'act_order', null, null, $at, 'host-3'));
        $stream->emit(new TokenDelta('cue_h5', 5, 'act_order', null, null, $at, 'host-5'));
        $stream->emit(new TokenDelta('cue_h7', 7, 'act_order', null, null, $at, 'host-7'));

        $merged   = $stream->stream()->toArray();
        $sequences = array_map(static fn(Cue $c): int => $c->sequence, $merged);

        self::assertCount(7, $merged);

        $sorted = $sequences;
        sort($sorted);
        self::assertSame($sorted, $sequences, 'Merged stream must be monotonically ordered by sequence');
    }

    #[Test]
    public function hostCuesEmittedAfterProviderExhaustionAppendAtEnd(): void
    {
        $scope = new ScopeStub();
        $at    = new \DateTimeImmutable('2026-05-18T10:00:00Z');

        $providerCues = [
            new TokenDelta('cue_p1', 1, 'act_tail', null, null, $at, 'p1'),
            new TokenDelta('cue_p2', 2, 'act_tail', null, null, $at, 'p2'),
        ];

        $stream = CompositeStream::wrap($scope, Stream::from($providerCues));
        $stream->emit(new TokenDelta('cue_h10', 10, 'act_tail', null, null, $at, 'host-tail'));

        $merged    = $stream->stream()->toArray();
        $sequences = array_map(static fn(Cue $c): int => $c->sequence, $merged);

        self::assertCount(3, $merged);
        self::assertSame(10, end($sequences));
    }
}
