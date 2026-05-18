<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Stream;

use Phalanx\Panoply\Clock\FrozenClock;
use Phalanx\Panoply\Cue\Effect;
use Phalanx\Panoply\Cue\Invocation;
use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Duration;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the Stream::coalescing() windowing semantics.
 * Uses FrozenClock for deterministic time control.
 */
final class CoalescingTest extends TestCase
{
    #[Test]
    public function adjacentTokenDeltasSameChannelWithinWindowMerge(): void
    {
        // Three Message-channel deltas, clock not advanced → all within window.
        $clock = new FrozenClock(0);
        $at    = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('d1', 1, 'Pericles', Channel::Message, $at),
            self::delta('d2', 2, ' leads', Channel::Message, $at),
            self::delta('d3', 3, ' the agora.', Channel::Message, $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame('Pericles leads the agora.', $cues[0]->text);
        // Identity preserved from the first delta
        self::assertSame('d1', $cues[0]->id);
        self::assertSame(Channel::Message, $cues[0]->channel);
    }

    #[Test]
    public function differentChannelFlushesBuffer(): void
    {
        $clock = new FrozenClock(0);
        $at    = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('t1', 1, 'Themistocles', Channel::Message, $at),
            self::delta('t2', 2, ' thinks.', Channel::Thinking, $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(2, $cues);
        self::assertSame(Channel::Message, $cues[0]->channel);
        self::assertSame(Channel::Thinking, $cues[1]->channel);
    }

    #[Test]
    public function windowElapsedFlushesBuffer(): void
    {
        $clock = new FrozenClock(0);
        $at    = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = new Stream(static function () use ($clock, $at): \Generator {
            yield self::delta('a1', 1, 'Achilles', Channel::Message, $at);
            // Advance past the 50 ms window before the second delta
            $clock->advance(Duration::ms(100));
            yield self::delta('a2', 2, ' charges.', Channel::Message, $at);
        });

        $cues = $stream->coalescing(Duration::ms(50), $clock)->toArray();

        self::assertCount(2, $cues);
        self::assertSame('Achilles', $cues[0]->text);
        self::assertSame(' charges.', $cues[1]->text);
    }

    #[Test]
    public function effectCuePassesThroughAndFlushesPendingTokens(): void
    {
        $clock = new FrozenClock(0);
        $at    = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('d1', 1, 'Leonidas', Channel::Message, $at),
            new Effect\Requested(
                'e1',
                2,
                'act.thermopylae',
                'inv.01',
                'agent.sparta',
                $at,
                effectId: 'eff.01',
                kind: EffectKind::FileRead,
                summary: 'Read formation data',
            ),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(2, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame('Leonidas', $cues[0]->text);
        self::assertInstanceOf(Effect\Requested::class, $cues[1]);
    }

    #[Test]
    public function lifecycleCuePassesThroughAndFlushesPendingTokens(): void
    {
        $clock = new FrozenClock(0);
        $at    = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('d1', 1, 'At Thermopylae', Channel::Message, $at),
            new Invocation\Started('i1', 2, 'act.sparta', 'inv.01', 'agent.leonidas', $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(2, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertInstanceOf(Invocation\Started::class, $cues[1]);
    }

    #[Test]
    public function endOfStreamFlushesTrailingTokenDelta(): void
    {
        $clock = new FrozenClock(0);
        $at    = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('d1', 1, 'Olympus stands.', Channel::Message, $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(1, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame('Olympus stands.', $cues[0]->text);
    }

    #[Test]
    public function emptyStreamYieldsEmpty(): void
    {
        $clock = new FrozenClock(0);

        $cues = Stream::from([])->coalescing(Duration::ms(200), $clock)->toArray();

        self::assertSame([], $cues);
    }

    #[Test]
    public function nonTokenCuesAlonePassThrough(): void
    {
        $clock = new FrozenClock(0);
        $at    = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            new Effect\Requested(
                'e1',
                1,
                'act.agora',
                'inv.01',
                'agent.pericles',
                $at,
                effectId: 'eff.01',
                kind: EffectKind::FileRead,
                summary: 'Consult scrolls',
            ),
            new Invocation\Started('s1', 2, 'act.agora', 'inv.01', 'agent.pericles', $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(2, $cues);
        self::assertInstanceOf(Effect\Requested::class, $cues[0]);
        self::assertInstanceOf(Invocation\Started::class, $cues[1]);
    }

    private static function delta(
        string $id,
        int $sequence,
        string $text,
        Channel $channel,
        \DateTimeImmutable $at,
    ): TokenDelta {
        return new TokenDelta(
            id: $id,
            sequence: $sequence,
            activityId: 'act.thermopylae',
            invocationId: 'inv.01',
            agentId: 'agent.leonidas',
            at: $at,
            text: $text,
            channel: $channel,
        );
    }
}
