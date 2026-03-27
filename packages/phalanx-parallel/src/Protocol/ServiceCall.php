<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Protocol;

final readonly class ServiceCall
{
    public function __construct(
        public string $id,
        public string $serviceClass,
        public string $method,
        /** @var list<mixed> */
        public array $args = [],
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['id'], $data['service'], $data['method'])) {
            throw new \InvalidArgumentException(
                'ServiceCall requires "id", "service", and "method" keys, got: ' . implode(', ', array_keys($data))
            );
        }

        return new self(
            id: $data['id'],
            serviceClass: $data['service'],
            method: $data['method'],
            args: $data['args'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'args' => $this->args,
            'method' => $this->method,
            'service' => $this->serviceClass,
            'type' => MessageType::ServiceCall->value,
        ];
    }
}
