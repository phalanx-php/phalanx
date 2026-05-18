<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Stream\CompositeStream;
use Phalanx\Athena\Tests\Fixtures\ScopeStub;
use Phalanx\Panoply\Cue\Activity\Completed;
use Phalanx\Panoply\Cue\Activity\Started;
use Phalanx\Panoply\Cue\Effect\Requested;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Cue\Output\TokenStop;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect\Kind;
use Phalanx\Panoply\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompositeStreamTest extends TestCase
{
    #[Test]
    public function providerOnlyStreamPassesThroughUnchanged(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = Stream::from([
            new TokenDelta('cue_1', 1, 'act_1', null, 'agent_1', $at, 'hello'),
            new TokenStop('cue_2', 2, 'act_1', null, 'agent_1', $at, StopReason::EndOfTurn),
        ]);
        $composite = CompositeStream::wrap($provider, new ScopeStub());

        $cues = $composite->stream()->toArray();

        self::assertSame(['cue_1', 'cue_2'], array_map(static fn($cue): string => $cue->id, $cues));
    }

    #[Test]
    public function hostCuesCanInterleaveWithProviderCuesDuringIteration(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = Stream::from([
            new TokenDelta('cue_2', 2, 'act_1', null, 'agent_1', $at, 'hello'),
            new TokenStop('cue_4', 4, 'act_1', null, 'agent_1', $at, StopReason::EndOfTurn),
        ]);
        $composite = CompositeStream::wrap($provider, new ScopeStub());
        $composite->emit(new Started('cue_1', 1, 'act_1', null, 'agent_1', $at));

        $sequences = [];
        foreach ($composite->stream() as $cue) {
            $sequences[] = $cue->sequence;
            if ($cue->sequence === 2) {
                $composite->emit(new Completed('cue_3', 3, 'act_1', null, 'agent_1', $at));
            }
        }

        self::assertSame([1, 2, 3, 4], $sequences);
    }

    #[Test]
    public function hostCuesOnlyYieldInSequenceOrderWhenProviderIsEmpty(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $composite = CompositeStream::wrap(Stream::from([]), new ScopeStub());
        $composite->emit(new Completed('cue_3', 3, 'act_1', null, 'agent_1', $at));
        $composite->emit(new Started('cue_1', 1, 'act_1', null, 'agent_1', $at));
        $composite->emit(new Started('cue_2', 2, 'act_1', null, 'agent_1', $at));

        $sequences = array_map(
            static fn($cue): int => $cue->sequence,
            $composite->stream()->toArray(),
        );

        self::assertSame([1, 2, 3], $sequences);
    }

    #[Test]
    public function compositeStreamPreservesPanoplyFilters(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $provider = Stream::from([
            new TokenDelta('cue_2', 2, 'act_1', null, 'agent_1', $at, 'hello'),
            new Requested(
                id: 'cue_3',
                sequence: 3,
                activityId: 'act_1',
                invocationId: null,
                agentId: 'agent_1',
                at: $at,
                effectId: 'eff_1',
                kind: Kind::FileRead,
                summary: 'read file',
            ),
        ]);
        $composite = CompositeStream::wrap($provider, new ScopeStub());
        $composite->emit(new Started('cue_1', 1, 'act_1', null, 'agent_1', $at));
        $stream = $composite->stream();

        self::assertCount(1, $stream->tokens()->toArray());
        self::assertCount(1, $stream->effects()->toArray());
        self::assertCount(1, $stream->lifecycle()->toArray());
    }
}
