<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

class ExchangeSummary
{
    public function __construct(
        private(set) string $id,
        private(set) string $userPreview,
        private(set) string $assistantPreview,
        private(set) int $toolCallCount,
        private(set) int $lineOffset,
        private(set) float $timestamp,
    ) {
    }

    public static function fromExchange(Exchange $exchange, int $lineOffset): self
    {
        return new self(
            id: $exchange->id,
            userPreview: mb_substr($exchange->userMessage, 0, 100),
            assistantPreview: mb_substr($exchange->assistantResponse, 0, 100),
            toolCallCount: count($exchange->toolCalls),
            lineOffset: $lineOffset,
            timestamp: microtime(true),
        );
    }

    public function displaySummary(): string
    {
        $user = mb_strlen($this->userPreview) > 25
            ? mb_substr($this->userPreview, 0, 22) . '...'
            : $this->userPreview;

        if ($this->assistantPreview === '') {
            return "human: {$user}";
        }

        $firstLine = strtok($this->assistantPreview, "\n") ?: $this->assistantPreview;
        $assist = mb_strlen($firstLine) > 50
            ? mb_substr($firstLine, 0, 47) . '...'
            : $firstLine;

        return "human: {$user}  →  {$assist}";
    }
}
