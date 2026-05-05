<?php

declare(strict_types=1);

namespace Acme;

use Acme\Tools\CrossReference;
use Acme\Tools\ExtractDocumentContent;
use Acme\Tools\QuerySpreadsheet;
use Phalanx\Athena\AgentDefinition;
use Phalanx\Athena\AgentLoop;
use Phalanx\Athena\Turn;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\HasTimeout;
use Phalanx\Task\Retryable;

final class ResearchAgent implements AgentDefinition, Retryable, HasTimeout
{
    public string $instructions {
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

    public RetryPolicy $retryPolicy {
        get => RetryPolicy::exponential(2);
    }

    public float $timeout {
        get => 60.0;
    }

    public function tools(): array
    {
        return [
            ExtractDocumentContent::class,
            QuerySpreadsheet::class,
            CrossReference::class,
        ];
    }

    public function provider(): ?string
    {
        return 'anthropic';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return AgentLoop::run(Turn::begin($this), $scope);
    }
}
