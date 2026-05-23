<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity;
use Phalanx\Panoply\Cue\Output;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CueTest extends TestCase
{
    #[Test]
    public function baseFieldsAreCarried(): void
    {
        $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
        $cue = new Activity\Started(
            id: 'cue_1',
            sequence: 0,
            activityId: 'act_1',
            invocationId: null,
            agentId: 'investigator',
            at: $at,
        );

        self::assertSame('cue_1', $cue->id);
        self::assertSame(0, $cue->sequence);
        self::assertSame('act_1', $cue->activityId);
        self::assertNull($cue->invocationId);
        self::assertSame('investigator', $cue->agentId);
        self::assertSame($at, $cue->at);
        self::assertSame('cue.activity.started', $cue->type);
    }

    #[Test]
    public function instanceofNarrowsConcreteSubclass(): void
    {
        // Construct as the base type so instanceof narrowing is meaningful.
        $cue = self::cueBase();

        // Check negative case first (before PHPStan narrows the type via assertInstanceOf).
        self::assertNotInstanceOf(Activity\Started::class, $cue);
        self::assertInstanceOf(Cue::class, $cue);
        self::assertInstanceOf(Output\TokenDelta::class, $cue);
    }

    #[Test]
    public function canonicalFormIncludesTypeAndPayload(): void
    {
        $cue = self::tokenDelta();
        $canonical = $cue->toCanonical();

        self::assertSame('cue.output.token_delta', $canonical['type']);
        self::assertArrayHasKey('payload', $canonical);
        self::assertSame('hello ', $canonical['payload']['text']);
        self::assertSame('message', $canonical['payload']['channel']);
    }

    #[Test]
    public function hashIsStable(): void
    {
        $a = self::tokenDelta();
        $b = self::tokenDelta();

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentPayloadsProduceDifferentHashes(): void
    {
        $a = self::tokenDelta(text: 'hello ');
        $b = self::tokenDelta(text: 'world!');

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(self::tokenDelta());

        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }

    /** Returns a TokenDelta typed as the base Cue class so instanceof assertions are non-trivial. */
    private static function cueBase(): Cue
    {
        return self::tokenDelta();
    }

    private static function tokenDelta(string $text = 'hello '): Output\TokenDelta
    {
        return new Output\TokenDelta(
            id: 'cue_token_1',
            sequence: 4,
            activityId: 'act_1',
            invocationId: 'inv_1',
            agentId: 'investigator',
            at: new \DateTimeImmutable('2026-05-17T12:00:00Z'),
            text: $text,
        );
    }
}
