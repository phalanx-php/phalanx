<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;
use Throwable;

final class RecoveryDecision
{
    /** @param ?array<string, mixed> $parameters */
    public function __construct(
        private(set) RecoveryAction $action,
        private(set) ?Mark $delay = null,
        private(set) ?RecoveryPlan $nextPlan = null,
        private(set) ?array $parameters = null,
        private(set) ?Throwable $error = null,
    ) {
    }
}
