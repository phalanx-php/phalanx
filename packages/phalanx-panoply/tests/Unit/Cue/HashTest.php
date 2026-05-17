<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Cue;

use Phalanx\Panoply\Cue\Output\Channel;
use Phalanx\Panoply\Cue\Output\TokenDelta;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HashTest extends TestCase
{
    #[Test]
    public function tokenDeltaHashStableAcrossInstances(): void
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

    #[Test]
    public function tokenDeltaDifferentChannelsHashDifferently(): void
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

    #[Test]
    public function nullOptionalFieldsHashDifferentlyThanPopulated(): void
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

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $cue = new TokenDelta(
            id: 'cue_1',
            sequence: 0,
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'investigator',
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            text: 'marathon',
        );

        $hash = Canonical::of($cue);

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    #[Test]
    public function timestampInDifferentTimezonesHashesIdentically(): void
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
