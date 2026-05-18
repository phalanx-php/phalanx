<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Fixtures\Agent\RequiredConstructor;

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
 * Fixture for Attribute loader non-trivial-constructor tests. Carries
 * #[Discovered] and implements Agent, but requires a constructor argument.
 * The loader must throw {@see \Phalanx\Panoply\Agent\Loader\LoaderError::nonTrivialConstructor()}.
 */
#[Discovered]
final class RequiredArgAgent implements Agent
{
    public string $id { get => 'required-arg'; }

    public string $name { get => 'RequiredArg'; }

    public string $purpose { get => 'Fixture agent with required constructor arg.'; }

    public Capabilities $capabilities { get => Capabilities::of(Capability::Reasoning); }

    public Context $context { get => Context::new(); }

    public Effects $effects { get => Effects::allow(EffectKind::FileRead); }

    public ProviderNeeds $provider {
        get => ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning);
    }

    public TransportNeeds $transport { get => TransportNeeds::new()->streaming(); }

    public Output $output { get => Output::artifact(ArtifactKind::Thesis); }

    public function __construct(public string $requiredArg)
    {
    }
}
