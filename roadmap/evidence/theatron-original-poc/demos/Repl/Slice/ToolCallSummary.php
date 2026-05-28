<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

class ToolCallSummary
{
    public function __construct(
        private(set) string $toolName,
        private(set) string $argumentsSummary,
        private(set) ?string $status = null,
        private(set) ?string $resultContent = null,
        private(set) ?string $resultType = null,
        private(set) bool $expanded = false,
    ) {
    }

    public function withStatus(string $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withResult(string $content, string $type = 'text'): self
    {
        $clone = clone $this;
        $clone->resultContent = $content;
        $clone->resultType = $type;

        return $clone;
    }

    public function withExpanded(bool $expanded): self
    {
        $clone = clone $this;
        $clone->expanded = $expanded;

        return $clone;
    }
}
