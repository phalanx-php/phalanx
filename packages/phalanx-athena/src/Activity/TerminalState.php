<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

use Phalanx\Athena\Turn\Outcome;
use Phalanx\Panoply\Conversation\Log;

final class TerminalState
{
    public function __construct(
        private(set) State $state,
        private(set) Outcome $outcome,
        private(set) Log $log,
        private(set) int $invocations,
        private(set) ?\Throwable $error = null,
    ) {}
}
