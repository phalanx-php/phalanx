<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Conversation\Log;

final class Result
{
    public function __construct(
        private(set) string $activityId,
        private(set) State $state,
        private(set) Outcome $outcome,
        private(set) Log $log,
        private(set) int $invocations,
        private(set) ?\Throwable $error = null,
    ) {
        if ($this->activityId === '') {
            throw new \InvalidArgumentException('Activity id cannot be empty.');
        }

        if ($this->invocations < 0) {
            throw new \InvalidArgumentException('Activity invocation count must be >= 0.');
        }
    }
}
