<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Protocol;

final class TaskRequest
{
    public function __construct(
        private(set) string $id,
        private(set) string $taskClass,
        /** @var array<string, mixed> */
        private(set) array $constructorArgs,
        /** @var array<string, mixed> */
        private(set) array $contextAttrs = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id'], $data['task'])) {
            throw new \InvalidArgumentException(
                'TaskRequest requires "id" and "task" keys, got: ' . implode(', ', array_keys($data))
            );
        }

        return new self(
            id: $data['id'],
            taskClass: $data['task'],
            constructorArgs: $data['args'] ?? [],
            contextAttrs: $data['context'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'task' => $this->taskClass,
            'args' => $this->constructorArgs,
            'context' => $this->contextAttrs,
            'type' => MessageType::TaskRequest->value,
        ];
    }
}
