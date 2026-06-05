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
 * Fixture agent for Attribute loader tests. Represents a phalanx-formation
 * strategy agent discovered via the #[Discovered] marker attribute.
 */
#[Discovered]
final class PhalanxAgent implements Agent
{
    public string $id { get => 'phalanx'; }

    public string $name { get => 'Phalanx'; }

    public string $purpose { get => 'Coordinate the phalanx formation across the battlefield.'; }

    public Capabilities $capabilities { get => Capabilities::of(Capability::ToolUse); }

    public Context $context { get => Context::new(); }

    public Effects $effects { get => Effects::allow(EffectKind::FileRead, EffectKind::CodeSearch); }

    public ProviderNeeds $provider {
        get => ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::ToolUse);
    }

    public TransportNeeds $transport { get => TransportNeeds::new()->streaming()->cancellable(); }

    public Output $output { get => Output::artifact(ArtifactKind::Thesis); }
}
