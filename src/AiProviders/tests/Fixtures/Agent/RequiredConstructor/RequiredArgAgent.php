<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Fixtures\Agent\RequiredConstructor;

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
 * Fixture for Attribute loader non-trivial-constructor tests. Carries
 * #[Discovered] and implements Agent, but requires a constructor argument.
 * The loader must throw {@see \Phalanx\AiProviders\Agent\Loader\LoaderError::nonTrivialConstructor()}.
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
