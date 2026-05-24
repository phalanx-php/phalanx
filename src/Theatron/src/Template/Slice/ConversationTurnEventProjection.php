<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

final class ConversationTurnEventProjection
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $reasonCodes
     */
    public function __construct(
        private(set) ConversationTurnEventKind $kind,
        private(set) ConversationTurnEventSeverity $severity,
        private(set) string $label,
        private(set) ?string $summary = null,
        private(set) ?string $effectId = null,
        private(set) ?string $effectKind = null,
        private(set) array $arguments = [],
        private(set) ?string $argumentsDelta = null,
        private(set) ?string $grantId = null,
        private(set) ?int $durationMs = null,
        private(set) ?string $resultDigest = null,
        private(set) array $reasonCodes = [],
        private(set) ?string $reason = null,
        private(set) ?string $errorClass = null,
        private(set) ?int $inputTokens = null,
        private(set) ?int $outputTokens = null,
        private(set) ?int $cacheReadTokens = null,
        private(set) ?int $cacheWriteTokens = null,
        private(set) ?float $costUsd = null,
        private(set) ?string $stopReason = null,
    ) {
    }

    public function usageTotal(): ?int
    {
        if ($this->inputTokens === null || $this->outputTokens === null) {
            return null;
        }

        return $this->inputTokens + $this->outputTokens;
    }

    public function isTokenText(): bool
    {
        return match ($this->kind) {
            ConversationTurnEventKind::MessageDelta,
            ConversationTurnEventKind::ReasoningDelta,
            ConversationTurnEventKind::ThinkingDelta => true,
            default => false,
        };
    }

    public function rendersInThread(): bool
    {
        if ($this->isTokenText()) {
            return false;
        }

        if ($this->kind === ConversationTurnEventKind::TokenStop) {
            return $this->stopReason !== 'end-of-turn'
                && $this->stopReason !== 'stop-sequence';
        }

        if ($this->kind === ConversationTurnEventKind::InvocationCompleted) {
            return $this->stopReason !== 'end-of-turn'
                && $this->stopReason !== 'stop-sequence';
        }

        return $this->kind !== ConversationTurnEventKind::ActivityCompleted
            && $this->kind !== ConversationTurnEventKind::ActivityStarted
            && $this->kind !== ConversationTurnEventKind::InvocationStarted;
    }
}
