<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Conversation\Record;

use Phalanx\Panoply\Conversation\Record;
use Phalanx\Panoply\Conversation\RecordType;

/**
 * The result returned to the agent after a tool call. `callId` matches
 * the corresponding {@see ToolCall} record. `isError` signals that the
 * tool reported failure — the `output` string carries the error message
 * or stack trace in that case.
 */
final class ToolResult extends Record
{
    final public RecordType $type { get => RecordType::ToolResult; }

    public function __construct(
        string $id,
        ?int $sequence,
        \DateTimeImmutable $at,
        private(set) string $callId,
        private(set) string $output,
        private(set) bool $isError = false,
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
            'output' => $this->output,
            'is_error' => $this->isError,
        ];
    }
}
