<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

class ActiveTurn
{
    public function __construct(
        private(set) string $userMessage,
        private(set) string $streamedText = '',
        /** @var list<ToolCallSummary> */
        private(set) array $toolCalls = [],
        private(set) ?string $thinkingContent = null,
        private(set) bool $isComplete = false,
    ) {
    }

    public function appendText(string $delta): self
    {
        $clone = clone $this;
        $clone->streamedText .= $delta;

        return $clone;
    }

    public function appendThinking(string $delta): self
    {
        $clone = clone $this;
        $clone->thinkingContent = ($this->thinkingContent ?? '') . $delta;

        return $clone;
    }

    public function addToolCall(ToolCallSummary $call): self
    {
        $clone = clone $this;
        $clone->toolCalls = [...$this->toolCalls, $call];

        return $clone;
    }

    public function updateToolCall(string $toolName, string $status, ?string $resultContent = null, ?string $resultType = null): self
    {
        $updated = [];

        foreach ($this->toolCalls as $call) {
            if ($call->toolName === $toolName && ($call->status === 'running' || $call->status === null)) {
                $call = $call->withStatus($status);

                if ($resultContent !== null) {
                    $call = $call->withResult($resultContent, $resultType ?? 'text')
                        ->withExpanded(true);
                }
            }

            $updated[] = $call;
        }

        $clone = clone $this;
        $clone->toolCalls = $updated;

        return $clone;
    }

    public function finalize(): Exchange
    {
        return new Exchange(
            userMessage: $this->userMessage,
            assistantResponse: $this->streamedText,
            summary: Exchange::summarize($this->userMessage, $this->streamedText),
            toolCalls: $this->toolCalls,
            thinkingContent: $this->thinkingContent,
        );
    }
}
