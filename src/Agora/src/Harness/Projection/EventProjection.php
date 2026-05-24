<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness\Projection;

use Phalanx\Agora\Harness\Exception\DuplicateEventSequence;
use Phalanx\Agora\Harness\Exception\OutOfOrderEventSequence;
use Phalanx\Agora\Harness\Exception\SessionMismatch;
use Phalanx\Agora\Harness\HarnessEvent;
use Phalanx\Agora\Harness\Projection;
use Phalanx\Agora\Harness\ProjectionCheckpoint;
use Phalanx\Agora\Harness\ProjectionKind;

abstract class EventProjection implements Projection
{
    public function __construct(
        protected(set) string $sessionId,
        protected(set) int $eventSequence = 0,
    ) {
        if ($this->eventSequence < 0) {
            throw new \InvalidArgumentException('Projection event sequence cannot be negative.');
        }
    }

    final public function apply(
        HarnessEvent $event,
    ): static {
        $this->assertNext($event);

        $projection = clone $this;
        $projection->eventSequence = $event->sequence;
        $projection->applyEvent($event);

        return $projection;
    }

    final public function checkpoint(
        ?\DateTimeImmutable $createdAt = null,
    ): ProjectionCheckpoint {
        return ProjectionCheckpoint::forState(
            sessionId: $this->sessionId,
            stateKind: $this->kind(),
            eventSequence: $this->eventSequence,
            state: $this->state(),
            createdAt: $createdAt,
        );
    }

    final public function eventSequence(): int
    {
        return $this->eventSequence;
    }

    abstract public function kind(): ProjectionKind;

    /** @return array<string, mixed> */
    abstract public function state(): array;

    abstract protected function applyEvent(
        HarnessEvent $event,
    ): void;

    private function assertNext(
        HarnessEvent $event,
    ): void {
        if ($event->sessionId !== $this->sessionId) {
            throw SessionMismatch::expected($this->sessionId, $event->sessionId);
        }

        $expected = $this->eventSequence + 1;

        if ($event->sequence === $this->eventSequence) {
            throw DuplicateEventSequence::forSequence($event->sequence);
        }

        if ($event->sequence !== $expected) {
            throw OutOfOrderEventSequence::expected($expected, $event->sequence);
        }
    }
}
