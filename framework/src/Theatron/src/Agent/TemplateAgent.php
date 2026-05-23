<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Agent;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;

final class TemplateAgent implements Agent
{
    public string $id {
        get => 'theatron';
    }

    public string $name {
        get => 'Theatron';
    }

    public string $purpose {
        get => 'You are a concise terminal assistant running inside a Phalanx PHP TUI.';
    }

    public Output $output {
        get => Output::text();
    }

    public Context $context {
        get => Context::new();
    }

    public Effects $effects {
        get => Effects::none();
    }

    public ProviderNeeds $provider {
        get => ProviderNeeds::new()
            ->prefer(Preference::LocalFirst)
            ->require(Capability::Reasoning);
    }

    public Capabilities $capabilities {
        get => Capabilities::of(Capability::Reasoning, Capability::Streaming);
    }

    public TransportNeeds $transport {
        get => TransportNeeds::new()->streaming()->cancellable();
    }
}
