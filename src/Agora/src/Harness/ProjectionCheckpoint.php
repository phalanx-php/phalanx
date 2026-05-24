<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hash\Canonicalizable;

final class ProjectionCheckpoint implements Canonicalizable
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        private(set) string $sessionId,
        private(set) ProjectionKind $stateKind,
        private(set) int $eventSequence,
        private(set) int $schemaVersion,
        private(set) string $projectionHash,
        private(set) array $state,
        private(set) \DateTimeImmutable $createdAt,
    ) {
        if ($this->eventSequence < 0) {
            throw new \InvalidArgumentException('Projection checkpoint event sequence cannot be negative.');
        }

        if ($this->schemaVersion < 1) {
            throw new \InvalidArgumentException('Projection checkpoint schema version must be greater than zero.');
        }
    }

    /**
     * @param array<string, mixed> $state
     */
    public static function forState(
        string $sessionId,
        ProjectionKind $stateKind,
        int $eventSequence,
        array $state,
        int $schemaVersion = 1,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            sessionId: $sessionId,
            stateKind: $stateKind,
            eventSequence: $eventSequence,
            schemaVersion: $schemaVersion,
            projectionHash: Canonical::of($state),
            state: $state,
            createdAt: $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    /** @return array<string, mixed> */
    public function toCanonical(): array
    {
        return [
            'session_id' => $this->sessionId,
            'state_kind' => $this->stateKind->value,
            'event_sequence' => $this->eventSequence,
            'schema_version' => $this->schemaVersion,
            'projection_hash' => $this->projectionHash,
            'state' => $this->state,
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
