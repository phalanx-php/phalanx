<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Stream;

use Phalanx\AiProviders\Clock\FrozenClock;
use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\Mark\Mark;
use Phalanx\AiProviders\Stream;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * High-volume smoke tests for Stream::coalescing().
 * Guard against O(N²) accumulation and verify merging and ordering behaviour
 * under large same-channel bursts and alternating-channel sequences.
 */
final class CoalescingHighVolumeTest extends TestCase
{
    #[Test]
    #[Group('performance')]
    public function coalesceHighVolumeStreamProducesSingleMergedDelta(): void
    {
        $count = 10_000;
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        // Greek text fragments repeated across 10k deltas.
        // Cycling through short token-sized chunks, matching real streaming output.
        $fragments = ['per', 'icl', 'es ', 'the', 'mis', 'toc', 'les', 'ach', 'ill', 'es '];
        $fragmentCount = count($fragments);

        $cues = [];
        $expectedText = '';

        for ($i = 0; $i < $count; $i++) {
            $text = $fragments[$i % $fragmentCount];
            $expectedText .= $text;
            $cues[] = new TokenDelta(
                id: 'd' . $i,
                sequence: $i,
                activityId: 'act.thermopylae',
                invocationId: 'inv.01',
                agentId: 'agent.leonidas',
                at: $at,
                text: $text,
                channel: Channel::Message,
            );
        }

        // Clock never advances — all 10k deltas fall within the 50 ms window.
        $before = memory_get_peak_usage(true);
        $result = Stream::from($cues)
            ->coalescing(Mark::ms(50), $clock)
            ->toArray();
        $afterMb = (memory_get_peak_usage(true) - $before) / (1024 * 1024);

        self::assertCount(1, $result, 'All same-channel deltas within window must merge to one.');
        self::assertInstanceOf(TokenDelta::class, $result[0]);
        self::assertSame($expectedText, $result[0]->text, 'Merged text must equal concatenation of all input texts.');
        self::assertSame('d0', $result[0]->id, 'Identity must be preserved from the first delta.');
        self::assertSame(Channel::Message, $result[0]->channel);

        // Memory guard: delta over the coalescing call must stay well under O(N²).
        // 8 MB is generous headroom for a correct O(N) implementation.
        self::assertLessThan(
            8.0,
            $afterMb,
            "Peak memory delta ({$afterMb} MB) exceeds 8 MB — possible O(N²) accumulation.",
        );
    }

    #[Test]
    #[Group('performance')]
    public function coalesceHighVolumeAlternatingChannelsPreservesOrderAndBoundsMemory(): void
    {
        $count = 10_000;
        $clock = new FrozenClock(0);
        $at = new \DateTimeImmutable('2026-05-18T00:00:00Z');

        // Three channels cycling — each delta is a different channel from its neighbour,
        // so no merging should occur. All 10 000 outputs must pass through unchanged.
        $channels = [Channel::Message, Channel::Thinking, Channel::Reasoning];
        $channelCount = count($channels);

        $cues = [];

        for ($i = 0; $i < $count; $i++) {
            $cues[] = new TokenDelta(
                id: 'a' . $i,
                sequence: $i,
                activityId: 'act.marathon',
                invocationId: 'inv.02',
                agentId: 'agent.configtocles',
                at: $at,
                text: 'x',
                channel: $channels[$i % $channelCount],
            );
        }

        // Clock never advances — all deltas fall within a single window,
        // but adjacent deltas are on different channels so none can merge.
        $before = memory_get_peak_usage(true);
        $result = Stream::from($cues)
            ->coalescing(Mark::ms(50), $clock)
            ->toArray();
        $afterMb = (memory_get_peak_usage(true) - $before) / (1024 * 1024);

        self::assertCount($count, $result, 'No merging occurs when adjacent deltas are on different channels.');

        // Order must be preserved exactly.
        foreach ($result as $idx => $cue) {
            self::assertInstanceOf(TokenDelta::class, $cue);
            self::assertSame('a' . $idx, $cue->id, "Delta at position {$idx} must retain its original identity.");
            self::assertSame($channels[$idx % $channelCount], $cue->channel);
        }

        // Memory guard: no merging path taken, so allocation should be minimal.
        self::assertLessThan(
            8.0,
            $afterMb,
            "Peak memory delta ({$afterMb} MB) exceeds 8 MB for alternating-channel sequence.",
        );
    }
}
