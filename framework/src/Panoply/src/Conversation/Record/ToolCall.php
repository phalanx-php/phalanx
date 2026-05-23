<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * The agent's invocation of a named tool. The `callId` links this record
 * to the corresponding {@see ToolResult}. Arguments are the raw
 * parsed payload the agent sent to the tool.
 */
final class ToolCall extends Record
{
    final public RecordType $type { get => RecordType::ToolCall; }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $callId,
        private(set) string $toolName,
        private(set) array $arguments,
    ) {
        parent::__construct($id, $sequence, $at);
    }

    /**
     * @return array<string, mixed>
     */
    final protected function payload(): array
    {
        return [
            'call_id' => $this->callId,
            'tool_name' => $this->toolName,
            'arguments' => $this->arguments,
        ];
    }
}
