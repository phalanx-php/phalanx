<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Workflow;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Cue;
use Phalanx\AiProviders\Cue\Activity\Completed;
use Phalanx\AiProviders\Cue\Activity\Started;
use Phalanx\AiProviders\Cue\Output\Channel;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Cue\Output\TokenStop;
use Phalanx\AiProviders\Cue\StopReason;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Fake\Provider;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Runtime\Sync\Runtime;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * In-process fake provider workflow loop.
 * Drives Fake\Provider with a scripted cue stream, collects via
 * Stream::tokens(), concatenates text deltas, and asserts the assembled
 * transcript matches expectations.
 *
 * Cross-reference: the v0 acceptance gate harness covers the same provider
 * surface at a coarser level via
 * {@see \Phalanx\AiProviders\Tests\Acceptance\V0AcceptanceGateTest::gate03FakeProviderEndToEndProducesValidCueStream()}.
 */
final class FakeLoopTest extends TestCase
{
    #[Test]
    public function fullCueStreamFlowsEndToEnd(): void
    {
        $provider = new Provider(self::script(), Capabilities::of(Capability::Reasoning));
        $stream = $provider->perform(self::invocation(), new Runtime());

        $allCues = $stream->toArray();

        self::assertCount(5, $allCues);
    }

    #[Test]
    public function tokensFilterYieldsOnlyTokenDeltaAndTokenStop(): void
    {
        $provider = new Provider(self::script(), Capabilities::of(Capability::Reasoning));
        $tokenStream = $provider->perform(self::invocation(), new Runtime())->tokens();

        foreach ($tokenStream as $cue) {
            self::assertThat(
                $cue,
                self::logicalOr(
                    self::isInstanceOf(TokenDelta::class),
                    self::isInstanceOf(TokenStop::class),
                ),
            );
        }
    }

    #[Test]
    public function transcriptAssemblesCorrectly(): void
    {
        $provider = new Provider(self::script(), Capabilities::of(Capability::Reasoning));
        $tokenStream = $provider->perform(self::invocation(), new Runtime())->tokens();

        $transcript = '';
        foreach ($tokenStream as $cue) {
            if ($cue instanceof TokenDelta) {
                $transcript .= $cue->text;
            }
        }

        self::assertSame('Hold the pass. We fight to the last.', $transcript);
    }

    #[Test]
    public function nonTokenCuesDoNotSurviveTokensFilter(): void
    {
        $provider = new Provider(self::script(), Capabilities::of(Capability::Reasoning));
        $tokenStream = $provider->perform(self::invocation(), new Runtime())->tokens()->toArray();

        // Started and Completed must be filtered out; only 2 TokenDelta + 1 TokenStop survive.
        self::assertCount(3, $tokenStream);
    }

    #[Test]
    public function streamYieldsNothingWhenScriptIsEmpty(): void
    {
        $provider = new Provider([], Capabilities::of(Capability::Reasoning));
        $cues = $provider->perform(self::invocation(), new Runtime())->toArray();

        self::assertSame([], $cues);
    }

    /**
     * @return list<Cue>
     */
    private static function script(): array
    {
        $at = new \DateTimeImmutable('2026-05-17T00:00:00Z');

        return [
            new Started('cue_01', 1, 'act_sparta', 'inv_01', 'leonidas', $at),
            new TokenDelta(
                'cue_02',
                2,
                'act_sparta',
                'inv_01',
                'leonidas',
                $at,
                'Hold the pass. ',
                Channel::Message,
            ),
            new TokenDelta(
                'cue_03',
                3,
                'act_sparta',
                'inv_01',
                'leonidas',
                $at,
                'We fight to the last.',
                Channel::Message,
            ),
            new TokenStop('cue_04', 4, 'act_sparta', 'inv_01', 'leonidas', $at, StopReason::EndOfTurn),
            new Completed('cue_05', 5, 'act_sparta', 'inv_01', 'leonidas', $at),
        ];
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_01',
            agentId: 'leonidas',
            activityId: 'act_sparta',
            contextHash: str_repeat('0', 64),
            instructions: 'Defend Thermopylae. Report your status.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            createdAt: new \DateTimeImmutable('2026-05-17T00:00:00Z'),
        );
    }
}
