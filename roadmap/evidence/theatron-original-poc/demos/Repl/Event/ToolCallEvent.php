<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Event;

use Phalanx\Theatron\Demos\Repl\Slice\ToolCallSummary;
use Phalanx\Theatron\Stream\StreamEvent;

class ToolCallEvent implements StreamEvent
{
    public function __construct(
        private(set) string $toolName,
        private(set) string $argumentsSummary,
        private(set) bool $started,
        private(set) ?string $result = null,
        private(set) ?string $resultContent = null,
        private(set) ?string $resultType = null,
    ) {
    }

    public function toSummary(): ToolCallSummary
    {
        $summary = new ToolCallSummary(
            toolName: $this->toolName,
            argumentsSummary: $this->argumentsSummary,
            status: $this->started ? 'running' : ($this->result ?? 'ok'),
        );

        if ($this->resultContent !== null) {
            $summary = $summary->withResult($this->resultContent, $this->resultType ?? 'text');
        }

        return $summary;
    }
}
