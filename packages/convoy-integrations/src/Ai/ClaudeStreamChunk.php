<?php

declare(strict_types=1);

namespace Convoy\Integration\Ai;

final class ClaudeStreamChunk
{
    public function __construct(
        public private(set) string $type,
        public private(set) ?string $text = null,
        public private(set) ?ToolCall $toolCall = null,
        public private(set) ?string $stopReason = null,
    ) {}

    public bool $isTextDelta {
        get => $this->type === 'content_block_delta' && $this->text !== null;
    }

    public bool $isToolUse {
        get => $this->type === 'content_block_start' && $this->toolCall !== null;
    }

    public bool $isStop {
        get => $this->type === 'message_delta' && $this->stopReason !== null;
    }

    /** @param array<string, mixed> $data */
    public static function fromSseEvent(string $event, array $data): ?self
    {
        return match ($event) {
            'content_block_delta' => match ($data['delta']['type'] ?? null) {
                'text_delta' => new self(
                    type: 'content_block_delta',
                    text: $data['delta']['text'] ?? '',
                ),
                'input_json_delta' => new self(
                    type: 'content_block_delta',
                    text: $data['delta']['partial_json'] ?? '',
                ),
                default => null,
            },
            'content_block_start' => match ($data['content_block']['type'] ?? null) {
                'tool_use' => new self(
                    type: 'content_block_start',
                    toolCall: new ToolCall(
                        id: $data['content_block']['id'],
                        name: $data['content_block']['name'],
                        input: [],
                    ),
                ),
                default => null,
            },
            'message_delta' => new self(
                type: 'message_delta',
                stopReason: $data['delta']['stop_reason'] ?? null,
            ),
            default => null,
        };
    }
}
