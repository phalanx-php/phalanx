<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Replay;

use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionSet;

final class ReplaySession
{
    /**
     * @param list<HarnessEvent> $events
     */
    public function __construct(
        private(set) string $sessionId,
        private(set) ProjectionSet $projections,
        private(set) array $events,
        private(set) int $checkpointSequence,
    ) {
    }
}
