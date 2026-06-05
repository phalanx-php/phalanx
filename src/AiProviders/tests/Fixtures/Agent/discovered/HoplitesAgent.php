<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Fixtures\Agent\Discovered;

use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Agent\Discovered;
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
