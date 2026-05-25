<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Replay;

use Phalanx\Agora\Harness\EventReader;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\ProjectionSet;

final class ReplaySessionReader
{
    public function __construct(
        private EventReader $events,
        private ProjectionCheckpointReader $checkpoints,
    ) {
    }

    public function read(
        string $sessionId,
    ): ReplaySession {
        $events = $this->orderedEvents($sessionId);
        $checkpoint = $this->checkpoints->latestProjectionSet($sessionId);
        $checkpointSequence = $checkpoint?->eventSequence() ?? 0;
        $projections = $checkpoint ?? ProjectionSet::empty($sessionId);

        foreach ($events as $event) {
            if ($event->sequence <= $checkpointSequence) {
                continue;
            }

            $projections = $projections->apply($event);
        }

        return new ReplaySession(
            sessionId: $sessionId,
            projections: $projections,
            events: $events,
            checkpointSequence: $checkpointSequence,
        );
    }

    /** @return list<HarnessEvent> */
    private function orderedEvents(
        string $sessionId,
    ): array {
        $events = [];

        foreach ($this->events->readAfter($sessionId, 0) as $event) {
            $events[] = $event;
        }

        return $events;
    }
}
