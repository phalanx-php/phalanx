<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tool;

final readonly class ToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            arguments: $data['input'] ?? $data['arguments'] ?? [],
        );
    }
}
