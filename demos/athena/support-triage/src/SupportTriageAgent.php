<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;

final class SupportTriageAgent implements Agent
{
    public string $id { get => 'support-triage-agent'; }

    public string $name { get => 'Support Triage Agent'; }

    public string $purpose {
        get => <<<'PROMPT'
            You are a senior support specialist. When given a support ticket:

            1. Classify its priority (critical/high/medium/low) and category
               (billing/technical/account/feature-request/bug-report)
            2. Look up the customer's account details and recent activity
            3. Check the knowledge base for relevant articles
            4. Draft a response that addresses the customer's issue directly

            If the issue is critical (service down, data loss, security),
            set priority to critical and include escalation instructions.
            If you can fully resolve the issue from the knowledge base,
            mark it as auto-resolvable.
            PROMPT;
    }

    public Output $output {
        get => Output::text();
    }

    public Context $context {
        get => Context::new();
    }

    public Effects $effects {
        get => Effects::allow(
            EffectKind::FileRead,
            EffectKind::CodeSearch,
            EffectKind::WebFetch,
        );
    }

    public ProviderNeeds $provider {
        get => ProviderNeeds::new()
            ->prefer(Preference::LocalFirst)
            ->require(Capability::ToolUse);
    }

    public Capabilities $capabilities {
        get => Capabilities::of(Capability::ToolUse, Capability::Streaming);
    }

    public TransportNeeds $transport {
        get => TransportNeeds::new()->streaming()->cancellable();
    }
}
