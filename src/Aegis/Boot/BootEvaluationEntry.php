<?php

declare(strict_types=1);

namespace Phalanx\Boot;

class BootEvaluationEntry
{
    public function __construct(
        private(set) BootRequirement $requirement,
        private(set) BootEvaluation $evaluation,
    ) {
    }
}
