<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Athena\Tool\ToolOutcome;
use Phalanx\Scope\Scope;

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
    ) {
    }

    public function __invoke(Scope $scope): ToolOutcome
    {
        return ToolOutcome::data([
            'question' => $this->question,
            'sources' => $this->documentIds,
            'findings' => 'Cross-referenced data across ' . count($this->documentIds) . ' documents.',
        ]);
    }
}
