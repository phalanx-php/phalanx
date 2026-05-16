<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Protocol;

final class StreamEmit
{
    public function __construct(
        private(set) string $taskId,
        private(set) string $eventClass,
        /** @var array<string, mixed> */
        private(set) array $payload = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['task_id'], $data['event_class'])) {
            throw new \InvalidArgumentException(
                'StreamEmit requires "task_id" and "event_class" keys, got: ' . implode(', ', array_keys($data)),
            );
        }

        return new self(
            taskId: $data['task_id'],
            eventClass: $data['event_class'],
            payload: $data['payload'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => MessageType::StreamEmit->value,
            'task_id' => $this->taskId,
            'event_class' => $this->eventClass,
            'payload' => $this->payload,
        ];
    }
}
