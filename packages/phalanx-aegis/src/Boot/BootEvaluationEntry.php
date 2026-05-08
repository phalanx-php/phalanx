<?php

declare(strict_types=1);

namespace Phalanx\Boot;

final readonly class BootEvaluationEntry
{
    public function __construct(
        public BootRequirement $requirement,
        public BootEvaluation $evaluation,
    ) {
    }
}
