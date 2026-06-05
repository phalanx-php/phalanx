<?php

declare(strict_types=1);

namespace Acme;

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

/**
 * Research agent that uses three tools (extract_document_content, query_spreadsheet,
 * cross_reference) to compile findings across uploaded documents. Tools return
 * scripted research data so the activity runs without a live file system or provider.
 */
final class ResearchAgent implements Agent
{
    public string $id { get => 'research-agent'; }

    public string $name { get => 'Research Agent'; }

    public string $purpose {
        get => <<<'PROMPT'
            You are a research analyst. Given a set of uploaded documents and a
            research question:

            1. Extract relevant content from each document (use extract_document_content
               for each). Be specific about what to focus on -- don't extract everything.
            2. If any document is a spreadsheet, use query_spreadsheet to run specific
               calculations or lookups.
            3. Cross-reference findings across documents to answer the question.
            4. Provide a structured analysis with citations to specific documents.

            Be thorough but efficient. Extract only what's needed to answer the question.
            Cite documents by their filename when making claims.
            PROMPT;
    }

    public Output $output {
        get => Output::artifact(ArtifactKind::InvestigationReport);
    }

    public Context $context {
        get => Context::new();
    }

    public Effects $effects {
        get => Effects::allow(EffectKind::FileRead);
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
