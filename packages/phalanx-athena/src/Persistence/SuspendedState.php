<?php

declare(strict_types=1);

namespace Phalanx\Athena\Persistence;

use Phalanx\Panoply\Conversation\Log;
use Phalanx\Panoply\Cue\Effect\Requested;

final class SuspendedState
{
    public function __construct(
        private(set) ActivityRecord $record,
        private(set) Log $log,
        private(set) Requested $pendingEffect,
    ) {
    }
}
