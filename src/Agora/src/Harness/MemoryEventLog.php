<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

use Phalanx\Agora\Harness\Exception\DuplicateEventSequence;
use Phalanx\Agora\Harness\Exception\OutOfOrderEventSequence;

final class MemoryEventLog implements EventLog
{
    /** @var array<string, array<int, HarnessEvent>> */
    private array $events = [];

    public function append(
        HarnessEvent $event,
    ): HarnessEvent {
        $sessionEvents = $this->events[$event->sessionId] ?? [];
        $expected = count($sessionEvents) + 1;

        if (isset($sessionEvents[$event->sequence])) {
            throw DuplicateEventSequence::forSequence($event->sequence);
        }

        if ($event->sequence !== $expected) {
            throw OutOfOrderEventSequence::expected($expected, $event->sequence);
        }

        $this->events[$event->sessionId][$event->sequence] = $event;

        return $event;
    }

    public function readAfter(
        string $sessionId,
        int $sequence,
    ): iterable {
        foreach ($this->events[$sessionId] ?? [] as $event) {
            if ($event->sequence > $sequence) {
                yield $event;
            }
        }
    }
}
