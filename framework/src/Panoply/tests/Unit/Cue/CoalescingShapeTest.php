<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Cue;

use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shape of Cue subtypes that the coalescing combinator operates on:
 * TokenDelta carries a `channel` field, distinct channels remain unmerged,
 * and concatenating text across two TokenDeltas preserves the channel
 * discriminator. Coalescing is a separate combinator; this test pins only
 * the TokenDelta surface it depends on.
 */
final class CoalescingShapeTest extends TestCase
{
    #[Test]
    public function concatenatingTextOfTwoTokenDeltasPreservesChannel(): void
    {
        $base = [
            'activityId' => 'act_1',
            'invocationId' => 'inv_1',
            'agentId' => null,
            'at' => new \DateTimeImmutable('2026-05-17T12:00:00Z'),
        ];

        $a = new TokenDelta(...$base, id: 'c_1', sequence: 0, text: 'hello ');
        $b = new TokenDelta(...$base, id: 'c_2', sequence: 1, text: 'world');

        self::assertSame(Channel::Message, $a->channel);
        self::assertSame(Channel::Message, $b->channel);
        self::assertSame('hello world', $a->text . $b->text);
    }

    #[Test]
    public function tokenDeltasOnDifferentChannelsShouldNotBeMerged(): void
    {
        $base = [
            'activityId' => 'act_1',
            'invocationId' => 'inv_1',
            'agentId' => null,
            'at' => new \DateTimeImmutable('2026-05-17T12:00:00Z'),
        ];

        $message = new TokenDelta(...$base, id: 'c_1', sequence: 0, text: 'reply', channel: Channel::Message);
        $thinking = new TokenDelta(...$base, id: 'c_2', sequence: 1, text: 'thought', channel: Channel::Thinking);

        self::assertNotSame($message->channel, $thinking->channel);
    }
}
