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
 * Runs calculations or lookups against a CSV/Excel file given a natural
 * language query. Returns scripted tabular results covering Spartan campaign
 * statistics so the ResearchAgent can reason over data without a live file system.
 */
final class QuerySpreadsheet implements Tool, SelfDescribed
{
    public string $description {
        get => 'Run calculations or data lookups against a spreadsheet file using a natural language query.';
    }

    public function __construct(
        #[Param('Path to the spreadsheet file')]
        private(set) string $filePath,
        #[Param('Natural language description of the calculation or lookup')]
        private(set) string $query,
    ) {
    }

    public function __invoke(TaskScope $scope, EffectContext $ctx): EffectOutcome
    {
        return EffectOutcome::routed(Resolution::LocalTool, data: [
            'file'   => $this->filePath,
            'query'  => $this->query,
            'result' => 'Spartan campaigns: 14 engagements, 11 decisive victories, 3 tactical retreats. ' .
                        'Average hoplite count per engagement: 7,200.',
        ]);
    }
}
