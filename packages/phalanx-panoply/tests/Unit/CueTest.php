<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity;
use Phalanx\Panoply\Cue\Output;
use Phalanx\Panoply\Hash\Canonical;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Cue::class)]
final class CueTest extends TestCase
{
    public function test_base_fields_are_carried(): void
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

    public function test_instanceof_narrows_concrete_subclass(): void
    {
        $cue = self::tokenDelta();

        self::assertInstanceOf(Cue::class, $cue);
        self::assertInstanceOf(Output\TokenDelta::class, $cue);
        self::assertNotInstanceOf(Activity\Started::class, $cue);
    }

    public function test_canonical_form_includes_type_and_payload(): void
    {
        $cue = self::tokenDelta();
        $canonical = $cue->toCanonical();

        self::assertSame('cue.output.token_delta', $canonical['type']);
        self::assertArrayHasKey('payload', $canonical);
        self::assertSame('hello ', $canonical['payload']['text']);
        self::assertSame('message', $canonical['payload']['channel']);
    }

    public function test_hash_is_stable(): void
    {
        $a = self::tokenDelta();
        $b = self::tokenDelta();

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    public function test_different_payloads_produce_different_hashes(): void
    {
        $a = self::tokenDelta(text: 'hello ');
        $b = self::tokenDelta(text: 'world!');

        self::assertNotSame(Canonical::of($a), Canonical::of($b));
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
