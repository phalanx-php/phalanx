<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function test_concrete_agent_property_hooks_resolve(): void
    {
        $agent = new TestInvestigatorAgent();

        self::assertSame('investigator', $agent->id);
        self::assertSame('Investigator', $agent->name);
        self::assertSame('Preserve context.', $agent->purpose);

        self::assertTrue($agent->capabilities->has(Capability::Reasoning));
        self::assertTrue($agent->capabilities->has(Capability::ToolUse));

        self::assertTrue($agent->provider->hasPreference(Preference::LocalFirst));
        self::assertTrue($agent->transport->streamingRequired);

        self::assertTrue($agent->effects->permits(EffectKind::FileRead));
        self::assertTrue($agent->effects->needsApproval(EffectKind::FileWrite));

        self::assertSame(Output\Mode::Artifact, $agent->output->mode);
        self::assertSame(ArtifactKind::Thesis, $agent->output->artifactKind);
    }

    public function test_agent_context_reflects_declared_sources(): void
    {
        $agent = new TestInvestigatorAgent();
        self::assertFalse($agent->context->isEmpty());
    }

    public function test_agents_are_value_types_pass_by_handle_not_state(): void
    {
        $a = new TestInvestigatorAgent();
        $b = new TestInvestigatorAgent();

        self::assertNotSame($a, $b);
        self::assertSame($a->id, $b->id);
    }
}

final class TestInvestigatorAgent implements Agent
{
    public string $id      { get => 'investigator'; }

    public string $name    { get => 'Investigator'; }

    public string $purpose { get => 'Preserve context.'; }

    public Capabilities $capabilities {
        get => Capabilities::of(Capability::Reasoning, Capability::ToolUse);
    }

    public Context $context {
        get => Context::new()
            ->front(TestSource\Mission::class)
            ->middle(TestSource\Excerpts::class)
            ->tail(TestSource\Question::class);
    }

    public ProviderNeeds $provider {
        get => ProviderNeeds::new()
            ->prefer(Preference::LocalFirst)
            ->fallback(Preference::Hosted)
            ->require(Capability::Reasoning);
    }

    public TransportNeeds $transport {
        get => TransportNeeds::new()->streaming()->cancellable();
    }

    public Effects $effects {
        get => Effects::allow(EffectKind::FileRead, EffectKind::CodeSearch)
            ->requireApproval(EffectKind::FileWrite);
    }

    public Output $output {
        get => Output::artifact(ArtifactKind::Thesis);
    }
}

namespace Phalanx\Panoply\Tests\Unit\TestSource;

final class Mission {}
final class Excerpts {}
final class Question {}
