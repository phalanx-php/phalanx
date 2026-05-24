<?php

declare(strict_types=1);

namespace Phalanx\Agora\Harness;

use Phalanx\Panoply\Hash\Canonicalizable;

final class ResumePoint implements Canonicalizable
{
    /**
     * @param array<string, mixed> $serializedContext
     */
    public function __construct(
        private(set) string $sessionId,
        private(set) ?string $turnId,
        private(set) int $eventSequence,
        private(set) ResumeStatus $status,
        private(set) ?string $pendingEffectRecordId,
        private(set) array $serializedContext,
        private(set) \DateTimeImmutable $updatedAt,
    ) {
        if ($this->eventSequence < 0) {
            throw new \InvalidArgumentException('Resume event sequence cannot be negative.');
        }
    }

    /** @return array<string, mixed> */
    public function toCanonical(): array
    {
        return [
            'session_id' => $this->sessionId,
            'turn_id' => $this->turnId,
            'event_sequence' => $this->eventSequence,
            'status' => $this->status->value,
            'pending_effect_record_id' => $this->pendingEffectRecordId,
            'serialized_context' => $this->serializedContext,
            'updated_at' => $this->updatedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
