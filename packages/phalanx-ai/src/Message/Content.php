<?php

declare(strict_types=1);

namespace Phalanx\Ai\Message;

final readonly class Content
{
    private function __construct(
        public ContentKind $kind,
        public ?string $text = null,
        public ?string $mediaType = null,
        public ?string $data = null,
        public ?string $toolCallId = null,
        public ?string $toolName = null,
        public mixed $toolInput = null,
        public mixed $toolResult = null,
    ) {}

    public static function text(string $text): self
    {
        return new self(kind: ContentKind::Text, text: $text);
    }

    public static function image(string $base64Data, string $mediaType = 'image/png'): self
    {
        return new self(kind: ContentKind::Image, data: $base64Data, mediaType: $mediaType);
    }

    public static function toolCall(string $id, string $name, mixed $input): self
    {
        return new self(kind: ContentKind::ToolCall, toolCallId: $id, toolName: $name, toolInput: $input);
    }

    public static function toolResult(string $id, mixed $result): self
    {
        return new self(kind: ContentKind::ToolResult, toolCallId: $id, toolResult: $result);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return match ($this->kind) {
            ContentKind::Text => ['type' => 'text', 'text' => $this->text],
            ContentKind::Image => ['type' => 'image', 'source' => [
                'type' => 'base64',
                'media_type' => $this->mediaType,
                'data' => $this->data,
            ]],
            ContentKind::ToolCall => ['type' => 'tool_use', 'id' => $this->toolCallId, 'name' => $this->toolName, 'input' => $this->toolInput],
            ContentKind::ToolResult => ['type' => 'tool_result', 'tool_use_id' => $this->toolCallId, 'content' => $this->toolResult],
        };
    }
}
