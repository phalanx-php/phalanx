<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Fixtures;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;

final class TestAgent implements Agent
{
    public string $id { get => 'agent-test-agent'; }

    public string $name { get => 'Agent Test Agent'; }

    public string $purpose { get => 'Summarize the current activity.'; }

    public Output $output {
        get => Output::artifact(ArtifactKind::Thesis);
    }

    public Context $context {
        get => Context::new()->front(Mission::class)->tail(Question::class);
    }

    public Effects $effects {
        get => Effects::allow(EffectKind::FileRead)->requireApproval(EffectKind::FileWrite);
    }

    public ProviderNeeds $provider {
        get => ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning);
    }

    public Capabilities $capabilities {
        get => Capabilities::of(Capability::Reasoning, Capability::Streaming);
    }

    public TransportNeeds $transport {
        get => TransportNeeds::new()->streaming()->cancellable();
    }
}

final class Mission
{
}

final class Question
{
}
