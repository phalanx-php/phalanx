<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

use Symfony\Component\Uid\Ulid;

class Exchange
{
    private(set) string $id;

    public function __construct(
        private(set) string $userMessage,
        private(set) string $assistantResponse,
        private(set) string $summary,
        /** @var list<ToolCallSummary> */
        private(set) array $toolCalls = [],
        private(set) ?string $thinkingContent = null,
        ?string $id = null,
    ) {
        $this->id = $id ?? (string) new Ulid();
    }

    /** @param list<ToolCallSummary> $toolCalls */
    public function withToolCalls(array $toolCalls): self
    {
        $clone = clone $this;
        $clone->toolCalls = $toolCalls;

        return $clone;
    }

    public static function summarize(string $userMessage, string $assistantResponse): string
    {
        $user = mb_strlen($userMessage) > 25
            ? mb_substr($userMessage, 0, 22) . '...'
            : $userMessage;

        if ($assistantResponse === '') {
            return "human: {$user}";
        }

        $firstLine = strtok($assistantResponse, "\n") ?: $assistantResponse;
        $assist = mb_strlen($firstLine) > 50
            ? mb_substr($firstLine, 0, 47) . '...'
            : $firstLine;

        return "human: {$user}  →  {$assist}";
    }
}
