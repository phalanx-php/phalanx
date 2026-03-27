<?php

declare(strict_types=1);

namespace Phalanx\Ai\Examples\ResearchAgent\Tools;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;
use Phalanx\Task\HasTimeout;

/**
 * Extracts and summarizes content from an uploaded document.
 *
 * Spawns a SummarizationAgent as a child scope to produce a focused
 * summary. Each document gets its own LLM call -- the main ResearchAgent
 * never sees raw document text.
 */
final class ExtractDocumentContent implements Tool, HasTimeout
{
    public string $description {
        get => 'Extract and summarize content from an uploaded document';
    }

    public float $timeout {
        get => 15.0;
    }

    public function __construct(
        #[Param('Path to the uploaded document')]
        private readonly string $documentPath,
        #[Param('What to focus on when extracting')]
        private readonly string $focus,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production: $scope->service(DocumentParser::class)->extract()
        // Then spawn SummarizationAgent via:
        //   AgentResult::awaitFrom(
        //       $scope->execute(Turn::begin(new SummarizationAgent())
        //           ->message(Message::user("Focus: {$this->focus}\n\nDocument: ..."))
        //           ->output(DocumentSummary::class))
        //   )

        return ToolOutcome::data([
            'document' => $this->documentPath,
            'focus' => $this->focus,
            'summary' => "Extracted key data points from {$this->documentPath} focused on {$this->focus}.",
            'key_points' => [
                'Revenue increased 15% QoQ',
                'Customer churn decreased to 2.3%',
            ],
        ]);
    }
}
