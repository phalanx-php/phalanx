<?php

declare(strict_types=1);

namespace Acme\Tools;

use Phalanx\Athena\Effect\Context as EffectContext;
use Phalanx\Athena\Effect\Outcome as EffectOutcome;
use Phalanx\Athena\Effect\Resolution;
use Phalanx\Athena\Tool\Param;
use Phalanx\Athena\Tool\Tool;
use Phalanx\Scope\TaskScope;
use Phalanx\SelfDescribed;

/**
 * Cross-references findings across multiple document summaries to answer
 * a focused question. Receives document summary IDs and a specific question,
 * then returns scripted cross-referenced analysis results.
 */
final class CrossReference implements Tool, SelfDescribed
{
    public string $description {
        get => 'Cross-reference findings across multiple document summaries to answer a specific research question.';
    }

    /**
     * @param list<string> $documentIds
     */
    public function __construct(
        #[Param('The specific question to answer by cross-referencing')]
        private(set) string $question,
        #[Param('Array of document summary IDs to cross-reference')]
        private(set) array $documentIds,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: [
            'question' => $this->question,
            'sources'  => $this->documentIds,
            'findings' => sprintf(
                'Cross-referenced %d documents. The phalanx formation accounts for ' .
                '73%% of decisive Spartan engagements recorded across all sources.',
                count($this->documentIds),
            ),
        ]);
    }
}
