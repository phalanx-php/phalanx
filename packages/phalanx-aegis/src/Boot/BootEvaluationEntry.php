<?php

declare(strict_types=1);

namespace Phalanx\Boot;

class BootEvaluationEntry
{
    public function __construct(
        public private(set) BootRequirement $requirement,
        public private(set) BootEvaluation $evaluation,
    ) {
    }
}
