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
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

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
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

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
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

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
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

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
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

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
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

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
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

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

    #[Test]
    public function channelTransitionStartsFreshWindowForNewBuffer(): void
    {
        // d1.Message → d2.Thinking (flushes d1, buffers d2) → advance 199 ms →
        // d3.Thinking same channel → still within 200 ms window → merge d2+d3.
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = new Stream(static function () use ($clock, $at): \Generator {
            yield self::delta('d1', 1, 'Phalanx', Channel::Message, $at);
            yield self::delta('d2', 2, 'thinks.', Channel::Thinking, $at);
            $clock->advance(Duration::ms(199));
            yield self::delta('d3', 3, ' More thoughts.', Channel::Thinking, $at);
        });

        $cues = $stream->coalescing(Duration::ms(200), $clock)->toArray();

        // d1 flushed by channel transition, d2+d3 merged in same window
        self::assertCount(2, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame(Channel::Message, $cues[0]->channel);
        self::assertSame('Phalanx', $cues[0]->text);
        self::assertInstanceOf(TokenDelta::class, $cues[1]);
        self::assertSame(Channel::Thinking, $cues[1]->channel);
        self::assertSame('thinks. More thoughts.', $cues[1]->text);
    }

    #[Test]
    public function exactlyAtWindowFlushes(): void
    {
        // Window is 50 ms. Advance EXACTLY 50 ms — boundary is exclusive (<),
        // so a delta arriving at elapsed == window must flush the buffer.
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = new Stream(static function () use ($clock, $at): \Generator {
            yield self::delta('e1', 1, 'Achilles', Channel::Message, $at);
            $clock->advance(Duration::ms(50));
            yield self::delta('e2', 2, ' runs.', Channel::Message, $at);
        });

        $cues = $stream->coalescing(Duration::ms(50), $clock)->toArray();

        // elapsed == window (50 µs × 1000 = 50 000 µs), condition is `< window`
        // so the second delta falls outside and flushes the buffer.
        self::assertCount(2, $cues);
        self::assertSame('Achilles', $cues[0]->text);
        self::assertSame(' runs.', $cues[1]->text);
    }

    #[Test]
    public function threeConsecutiveFlushesPreserveOrdering(): void
    {
        // d1.Message → d2.Thinking → d3.Message → d4.Reasoning, clock frozen.
        // Each channel switch flushes immediately, so output is 4 individual cues
        // in the same order as input.
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('f1', 1, 'Leonidas', Channel::Message, $at),
            self::delta('f2', 2, 'ponders.', Channel::Thinking, $at),
            self::delta('f3', 3, 'Commands.', Channel::Message, $at),
            self::delta('f4', 4, 'Deeper.', Channel::Reasoning, $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(4, $cues);
        self::assertSame('Leonidas', $cues[0]->text);
        self::assertSame('ponders.', $cues[1]->text);
        self::assertSame('Commands.', $cues[2]->text);
        self::assertSame('Deeper.', $cues[3]->text);
    }

    #[Test]
    public function burstLifecycleBurstPreservesOrder(): void
    {
        // 2 Message deltas → Invocation\Started (lifecycle) → 2 Message deltas.
        // Expected output: [merged d1+d2, Invocation\Started, merged d3+d4 (via EOF flush)].
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('b1', 1, 'Hold', Channel::Message, $at),
            self::delta('b2', 2, ' the', Channel::Message, $at),
            new Invocation\Started('s1', 3, 'act.sparta', 'inv.01', 'agent.leonidas', $at),
            self::delta('b3', 4, ' pass', Channel::Message, $at),
            self::delta('b4', 5, ' now.', Channel::Message, $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(3, $cues);
        self::assertInstanceOf(TokenDelta::class, $cues[0]);
        self::assertSame('Hold the', $cues[0]->text);
        self::assertInstanceOf(Invocation\Started::class, $cues[1]);
        self::assertInstanceOf(TokenDelta::class, $cues[2]);
        self::assertSame(' pass now.', $cues[2]->text);
    }

    #[Test]
    public function threeDifferentChannelsBackToBackProduceThreeOutputs(): void
    {
        // One delta per channel, no clock advance → each channel switch flushes.
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('g1', 1, 'Message text.', Channel::Message, $at),
            self::delta('g2', 2, 'Thinking text.', Channel::Thinking, $at),
            self::delta('g3', 3, 'Reasoning text.', Channel::Reasoning, $at),
        ])->coalescing(Duration::ms(200), $clock);

        $cues = $stream->toArray();

        self::assertCount(3, $cues);
        self::assertSame(Channel::Message, $cues[0]->channel);
        self::assertSame(Channel::Thinking, $cues[1]->channel);
        self::assertSame(Channel::Reasoning, $cues[2]->channel);
    }

    #[Test]
    public function coalesceWithZeroWindowFlushesEveryDelta(): void
    {
        // Duration::ms(0) means every delta is already beyond the window the
        // moment it arrives. Each delta must be emitted individually — no merging.
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        $stream = Stream::from([
            self::delta('z1', 1, 'First.', Channel::Message, $at),
            self::delta('z2', 2, ' Second.', Channel::Message, $at),
            self::delta('z3', 3, ' Third.', Channel::Message, $at),
        ])->coalescing(Duration::ms(0), $clock);

        $cues = $stream->toArray();

        self::assertCount(3, $cues, 'Zero-window coalescing must emit each delta separately');
        self::assertSame('First.', $cues[0]->text);
        self::assertSame(' Second.', $cues[1]->text);
        self::assertSame(' Third.', $cues[2]->text);
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
