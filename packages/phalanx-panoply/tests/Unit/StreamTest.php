<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Cue;
use Phalanx\Panoply\Cue\Activity;
use Phalanx\Panoply\Cue\Artifact;
use Phalanx\Panoply\Cue\Effect;
use Phalanx\Panoply\Cue\Output;
use Phalanx\Panoply\Cue\Provider;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Stream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Stream::class)]
final class StreamTest extends TestCase
{
    public function test_tokens_filters_to_token_cues(): void
    {
        $tokens = $this->mixedStream()->tokens()->toArray();

        self::assertCount(3, $tokens);
        foreach ($tokens as $cue) {
            self::assertTrue(
                $cue instanceof Output\TokenDelta || $cue instanceof Output\TokenStop,
            );
        }
    }

    public function test_effects_filters_to_effect_cues(): void
    {
        $effects = $this->mixedStream()->effects()->toArray();

        self::assertCount(2, $effects);
        foreach ($effects as $cue) {
            self::assertContains(
                $cue::class,
                [Effect\Requested::class, Effect\Authorized::class],
            );
        }
    }

    public function test_artifacts_filters_to_artifact_cues(): void
    {
        $artifacts = $this->mixedStream()->artifacts()->toArray();

        self::assertCount(1, $artifacts);
        self::assertInstanceOf(Artifact\Drafting::class, $artifacts[0]);
    }

    public function test_lifecycle_filters_to_activity_and_invocation_cues(): void
    {
        $lifecycle = $this->mixedStream()->lifecycle()->toArray();

        self::assertCount(2, $lifecycle);
        foreach ($lifecycle as $cue) {
            self::assertContains(
                $cue::class,
                [Activity\Started::class, Activity\Completed::class],
            );
        }
    }

    public function test_of_kind_with_no_args_is_identity(): void
    {
        $all = $this->mixedStream()->ofKind()->toArray();
        self::assertCount(9, $all);
    }

    private function mixedStream(): Stream
    {
        return new Stream(static function (): \Generator {
            $at = new \DateTimeImmutable('2026-05-17T12:00:00Z');
            $i = 0;

            yield new Activity\Started('c' . ++$i, $i, 'a1', null, null, $at);
            yield new Provider\Resolved(
                'c' . ++$i, $i, 'a1', 'i1', null, $at,
                provider: 'anthropic', model: 'claude-opus-4-7', reasonCode: 'preferred',
            );
            yield new Output\TokenDelta('c' . ++$i, $i, 'a1', 'i1', null, $at, text: 'hello');
            yield new Output\TokenDelta('c' . ++$i, $i, 'a1', 'i1', null, $at, text: ' world');
            yield new Effect\Requested(
                'c' . ++$i, $i, 'a1', 'i1', null, $at,
                effectId: 'eff1', kind: EffectKind::FileRead, summary: 'read README',
            );
            yield new Effect\Authorized('c' . ++$i, $i, 'a1', 'i1', null, $at, effectId: 'eff1', grantId: 'g1');
            yield new Output\TokenStop('c' . ++$i, $i, 'a1', 'i1', null, $at, reason: Cue\StopReason::EndOfTurn);
            yield new Artifact\Drafting(
                'c' . ++$i, $i, 'a1', 'i1', null, $at,
                artifactId: 'art1', kind: ArtifactKind::Thesis,
            );
            yield new Activity\Completed('c' . ++$i, $i, 'a1', 'i1', null, $at);
        });
    }
}
