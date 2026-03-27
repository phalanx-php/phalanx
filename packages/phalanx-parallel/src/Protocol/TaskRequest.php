<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Protocol;

final readonly class TaskRequest
{
    public function __construct(
        public string $id,
        public string $taskClass,
        /** @var array<string, mixed> */
        public array $constructorArgs,
        /** @var array<string, mixed> */
        public array $contextAttrs = [],
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
