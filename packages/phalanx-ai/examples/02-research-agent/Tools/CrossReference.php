<?php

declare(strict_types=1);

namespace Phalanx\Ai\Examples\ResearchAgent\Tools;

use Phalanx\Ai\Tool\Param;
use Phalanx\Ai\Tool\Tool;
use Phalanx\Ai\Tool\ToolOutcome;
use Phalanx\Scope;

final class CrossReference implements Tool
{
    public string $description {
        get => 'Cross-reference data points across multiple document summaries';
    }

    /**
     * @param list<string> $documentIds
     */
    public function __construct(
        #[Param('The specific question to answer by cross-referencing')]
        private readonly string $question,
        #[Param('Array of document summary IDs to cross-reference')]
        private readonly array $documentIds,
    ) {}

    public function __invoke(Scope $scope): ToolOutcome
    {
        // In production: Redis mget for cached summaries
        return ToolOutcome::data([
            'question' => $this->question,
            'sources' => $this->documentIds,
            'findings' => 'Cross-referenced data across ' . count($this->documentIds) . ' documents.',
        ]);
    }
}
