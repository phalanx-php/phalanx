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
