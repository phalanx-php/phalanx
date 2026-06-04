<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;
use Throwable;

final class RecoveryEvent
{
    public function __construct(
        private(set) RecoveryEventKind $kind,
        private(set) int $attempt,
        private(set) Mark $elapsed,
        private(set) ?Mark $remainingDeadline,
        private(set) ?Throwable $error,
        private(set) string $taskName,
        private(set) RecoveryPlan $plan,
    ) {
    }
}
