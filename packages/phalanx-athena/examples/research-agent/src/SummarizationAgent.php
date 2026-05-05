<?php

declare(strict_types=1);

namespace Acme;

use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\Scope\ExecutionScope;

/**
 * A focused sub-agent that summarizes a single document's content.
 *
 * Spawned by ExtractDocumentContent as a child scope. Each document
 * gets its own focused LLM call with a tight prompt, keeping the main
 * ResearchAgent's context window lean.
 */
final class SummarizationAgent implements AgentDefinition
{
    public string $instructions {
        get => <<<'PROMPT'
            You are a document summarization specialist. Given a document and a
            focus area, produce a concise structured summary that captures:

            1. Key data points relevant to the focus area
            2. Notable trends or patterns
            3. Any anomalies or outliers

            Be precise and cite specific numbers when available.
            PROMPT;
    }

    public function tools(): array
    {
        return [];
    }

    public function provider(): ?string
    {
        return null;
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }
}
