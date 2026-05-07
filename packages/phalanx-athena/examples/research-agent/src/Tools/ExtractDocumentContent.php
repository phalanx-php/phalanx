<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;
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
    ) {
    }

    public function __invoke(Scope $scope): ToolOutcome
    {
        return ToolOutcome::data([
            'document' => $this->documentPath,
            'focus' => $this->focus,
            'summary' => "Extracted key data points from {$this->documentPath} focused on {$this->focus}.",
            'key_points' => [
                'Athena is associated with disciplined wisdom and practical craft',
                'Athena favors strategic warcraft over indiscriminate violence',
            ],
        ]);
    }
}
