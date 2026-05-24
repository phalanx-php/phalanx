<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Fixtures;

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

final class TestAgent implements Agent
{
    public string $id { get => 'athena-test-agent'; }

    public string $name { get => 'Athena Test Agent'; }

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
