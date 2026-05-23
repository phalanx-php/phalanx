<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Fixtures\Agent\Discovered;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Agent\Discovered;
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

/**
 * Fixture agent for Attribute loader tests. Represents a hoplite-style
 * reasoning agent discovered via the #[Discovered] marker attribute.
 */
#[Discovered]
final class HoplitesAgent implements Agent
{
    public string $id { get => 'hoplites'; }

    public string $name { get => 'Hoplites'; }

    public string $purpose { get => 'Defend the phalanx formation with coordinated reasoning.'; }

    public Context $context { get => Context::new(); }

    public Effects $effects { get => Effects::allow(EffectKind::FileRead); }
    
    public Capabilities $capabilities { get => Capabilities::of(Capability::Reasoning); }

    public ProviderNeeds $provider {
        get => ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning);
    }

    public TransportNeeds $transport { get => TransportNeeds::new()->streaming(); }

    public Output $output { get => Output::artifact(ArtifactKind::Thesis); }
}
