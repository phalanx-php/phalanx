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
use Phalanx\Task\HasTimeout;

/**
 * Extracts and summarizes content from an uploaded document, focusing
 * on a caller-specified topic. Returns scripted key points so the parent
 * ResearchAgent can reason over document content without a live provider.
 */
final class ExtractDocumentContent implements Tool, HasTimeout, SelfDescribed
{
    public string $description {
        get => 'Extract and summarize content from an uploaded document, focusing on a specified topic or question.';
    }

    public float $timeout { get => 15.0; }

    public function __construct(
        #[Param('Path to the uploaded document')]
        private(set) string $documentPath,
        #[Param('What to focus on when extracting')]
        private(set) string $focus,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: [
            'document'   => $this->documentPath,
            'focus'      => $this->focus,
            'summary'    => "Extracted key data points from {$this->documentPath} focused on {$this->focus}.",
            'key_points' => [
                'Hoplites relied on disciplined formation over individual heroics',
                'The aspis (shield) was as much a social contract as a weapon',
                'Phalanx effectiveness degraded sharply outside flat terrain',
            ],
        ]);
    }
}
