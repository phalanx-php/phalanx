<?php

declare(strict_types=1);

namespace Phalanx\Integration\Ai;

final class ClaudeMessage
{
    /** @param string|list<array<string, mixed>> $content */
    private function __construct(
        public private(set) string $role,
        public private(set) string|array $content,
    ) {}

    public static function user(string $text): self
    {
        return new self('user', $text);
    }

    public static function assistant(string $text): self
    {
        return new self('assistant', $text);
    }

    /** @param array<string, mixed> $input */
    public static function toolUse(string $id, string $name, array $input): self
    {
        return new self('assistant', [[
            'type' => 'tool_use',
            'id' => $id,
            'name' => $name,
            'input' => $input,
        ]]);
    }

    public static function toolResult(string $toolUseId, string $content): self
    {
        return new self('user', [[
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $content,
        ]]);
    }

    /** @return array{role: string, content: string|list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['role' => $this->role, 'content' => $this->content];
    }
}
