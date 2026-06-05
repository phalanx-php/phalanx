<?php

declare(strict_types=1);

namespace Phalanx\Agents\Persistence;

use Phalanx\AiProviders\Conversation\Log;
use Phalanx\AiProviders\Cue\Effect\Requested;

final class SuspendedState
{
    public function __construct(
        private(set) ActivityRecord $record,
        private(set) Log $log,
        private(set) Requested $pendingEffect,
    ) {
    }
}
