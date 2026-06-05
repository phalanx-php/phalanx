<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\Fake;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Cue\Activity\Started;
use Phalanx\AiProviders\Cue\Output\TokenDelta;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Fake\Provider;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Runtime\CancellationException;
use Phalanx\AiProviders\Runtime\Sync\Runtime;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    #[Test]
    public function scriptedCuesStreamInOrder(): void
    {
        $cues = [self::startedCue(), self::tokenDeltaCue('Hello')];
        $provider = new Provider($cues, Capabilities::of(Capability::Reasoning));

        $collected = $provider->perform(self::invocation(), new Runtime())->toArray();

        self::assertCount(2, $collected);
        self::assertSame($cues[0], $collected[0]);
        self::assertSame($cues[1], $collected[1]);
    }

    #[Test]
    public function capabilitiesReturnsConstructorValue(): void
    {
        $caps = Capabilities::of(Capability::Reasoning, Capability::ToolUse);
        $provider = new Provider([], $caps);

        self::assertSame($caps, $provider->capabilities());
    }

    #[Test]
    public function cancellationIsHonoredBetweenCues(): void
    {
        $runtime = new Runtime();
        $provider = new Provider(
            [self::startedCue(), self::tokenDeltaCue('never')],
            Capabilities::of(Capability::Reasoning),
        );

        $this->expectException(CancellationException::class);

        $stream = $provider->perform(self::invocation(), $runtime);
        foreach ($stream as $cue) {
            $runtime->cancel();
        }
    }

    #[Test]
    public function emptyScriptYieldsNoItems(): void
    {
        $provider = new Provider([], Capabilities::empty());
        $collected = $provider->perform(self::invocation(), new Runtime())->toArray();

        self::assertSame([], $collected);
    }

    #[Test]
    public function performReturnsStream(): void
    {
        $provider = new Provider([], Capabilities::empty());

        self::assertInstanceOf(
            \Phalanx\AiProviders\Stream::class,
            $provider->perform(self::invocation(), new Runtime()),
        );
    }

    private static function startedCue(): Started
    {
        $at = new \DateTimeImmutable('2026-05-17T00:00:00Z');

        return new Started('cue_01', 1, 'act_leonidas', null, 'leonidas', $at);
    }

    private static function tokenDeltaCue(string $text): TokenDelta
    {
        $at = new \DateTimeImmutable('2026-05-17T00:00:01Z');

        return new TokenDelta('cue_02', 2, 'act_leonidas', 'inv_01', 'leonidas', $at, $text);
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_01',
            agentId: 'leonidas',
            activityId: 'act_leonidas',
            contextHash: str_repeat('0', 64),
            instructions: 'Hold the pass at Thermopylae.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::FileRead),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            createdAt: new \DateTimeImmutable('2026-05-17T00:00:00Z'),
        );
    }
}
