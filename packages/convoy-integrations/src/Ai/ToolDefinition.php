<?php

declare(strict_types=1);

namespace Convoy\Integration\Ai;

final class ToolDefinition
{
    /** @param array<string, mixed> $inputSchema */
    public function __construct(
        public private(set) string $name,
        public private(set) string $description,
        public private(set) array $inputSchema,
    ) {}

    /** @return array{name: string, description: string, input_schema: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'input_schema' => $this->inputSchema,
        ];
    }
}
