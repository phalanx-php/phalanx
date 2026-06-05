<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Cue\Activity;
use Phalanx\AiProviders\Cue\Artifact;
use Phalanx\AiProviders\Cue\Effect;
use Phalanx\AiProviders\Cue\Output;
use Phalanx\AiProviders\Cue\Provider;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Stream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StreamTest extends TestCase
{
    #[Test]
    public function tokensFiltersToTokenCues(): void
    {
        $tokens = $this->mixedStream()->tokens()->toArray();

        self::assertCount(3, $tokens);
        foreach ($tokens as $cue) {
            self::assertTrue(
                $cue instanceof Output\TokenDelta || $cue instanceof Output\TokenStop,
            );
        }
    }

    #[Test]
    public function effectsFiltersToEffectCues(): void
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

    #[Test]
    public function artifactsFiltersToArtifactCues(): void
    {
        $artifacts = $this->mixedStream()->artifacts()->toArray();

        self::assertCount(1, $artifacts);
        self::assertInstanceOf(Artifact\Drafting::class, $artifacts[0]);
    }

    #[Test]
    public function lifecycleFiltersToActivityAndInvocationCues(): void
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

    #[Test]
    public function ofKindWithNoArgsIsIdentity(): void
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
                'c' . ++$i,
                $i,
                'a1',
                'i1',
                null,
                $at,
                provider: 'anthropic',
                model: 'claude-opus-4-7',
                reasonCode: 'preferred',
            );
            yield new Output\TokenDelta('c' . ++$i, $i, 'a1', 'i1', null, $at, text: 'hello');
            yield new Output\TokenDelta('c' . ++$i, $i, 'a1', 'i1', null, $at, text: ' world');
            yield new Effect\Requested(
                'c' . ++$i,
                $i,
                'a1',
                'i1',
                null,
                $at,
                effectId: 'eff1',
                kind: EffectKind::FileRead,
                summary: 'read README',
            );
            yield new Effect\Authorized('c' . ++$i, $i, 'a1', 'i1', null, $at, effectId: 'eff1', grantId: 'g1');
            yield new Output\TokenStop('c' . ++$i, $i, 'a1', 'i1', null, $at, reason: Cue\StopReason::EndOfTurn);
            yield new Artifact\Drafting(
                'c' . ++$i,
                $i,
                'a1',
                'i1',
                null,
                $at,
                artifactId: 'art1',
                kind: ArtifactKind::Thesis,
            );
            yield new Activity\Completed('c' . ++$i, $i, 'a1', 'i1', null, $at);
        });
    }
}
