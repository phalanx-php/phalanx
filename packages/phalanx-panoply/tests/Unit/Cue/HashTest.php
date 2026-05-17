<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Cue;

use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    public function test_token_delta_hash_stable_across_instances(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $a = new TokenDelta(
            id: 'cue_1',
            sequence: 4,
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'investigator',
            at: $at,
            text: 'hello ',
        );
        $b = new TokenDelta(
            id: 'cue_1',
            sequence: 4,
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'investigator',
            at: $at,
            text: 'hello ',
        );

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    public function test_token_delta_different_channels_hash_differently(): void
    {
        $args = [
            'id'           => 'cue_1',
            'sequence'     => 0,
            'activityId'   => 'act_1',
            'invocationId' => null,
            'agentId'      => null,
            'at'           => new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            'text'         => 'reasoning content',
        ];

        $message  = new TokenDelta(...$args, channel: Channel::Message);
        $thinking = new TokenDelta(...$args, channel: Channel::Thinking);

        self::assertNotSame(Canonical::of($message), Canonical::of($thinking));
    }

    public function test_null_optional_fields_hash_differently_than_populated(): void
    {
        $shared = [
            'id'         => 'cue_1',
            'sequence'   => 0,
            'activityId' => 'act_1',
            'at'         => new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            'text'       => 'hello',
        ];

        $bare = new TokenDelta(...$shared, invocationId: null, agentId: null);
        $full = new TokenDelta(...$shared, invocationId: 'inv_1', agentId: 'investigator');

        self::assertNotSame(Canonical::of($bare), Canonical::of($full));
    }

    public function test_timestamp_in_different_timezones_hashes_identically(): void
    {
        $utc     = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $chicago = new \DateTimeImmutable('2026-05-17T07:00:00-05:00');
        $args = [
            'id'           => 'cue_1',
            'sequence'     => 0,
            'activityId'   => 'act_1',
            'invocationId' => 'inv_1',
            'agentId'      => 'investigator',
            'text'         => 'hello',
        ];

        $utcCue     = new TokenDelta(...$args, at: $utc);
        $chicagoCue = new TokenDelta(...$args, at: $chicago);

        self::assertSame(
            Canonical::of($utcCue),
            Canonical::of($chicagoCue),
            'cues representing the same instant must hash identically regardless of source timezone',
        );
    }
}
