<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Slice;

use Phalanx\Athena\Effect\Resolution;
use Phalanx\Panoply\Cue\StopReason;
use Phalanx\Panoply\Effect\Kind as EffectKind;

final class ConversationTurnEventProjection
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $reasonCodes
     * @param list<EffectKind> $allowedEffects
     * @param array<string, mixed> $conditions
     */
    public function __construct(
        private(set) ConversationTurnEventKind $kind,
        private(set) ConversationTurnEventSeverity $severity,
        private(set) string $label,
        private(set) ?string $summary = null,
        private(set) ?string $effectId = null,
        private(set) ?string $effectKind = null,
        private(set) ?Resolution $resolution = null,
        private(set) ?string $toolName = null,
        private(set) ?string $argsHash = null,
        private(set) ?string $outcome = null,
        private(set) ?string $subject = null,
        private(set) ?string $scope = null,
        private(set) ?string $hazardCeiling = null,
        private(set) ?\DateTimeImmutable $expiresAt = null,
        private(set) array $arguments = [],
        private(set) ?string $argumentsDelta = null,
        private(set) ?string $grantId = null,
        private(set) bool $requiresApproval = false,
        private(set) array $allowedEffects = [],
        private(set) array $conditions = [],
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
        private(set) ?StopReason $stopReason = null,
        private(set) ?string $artifactId = null,
        private(set) ?string $artifactKind = null,
        private(set) ?string $artifactPath = null,
        private(set) ?string $contentHash = null,
        private(set) ?string $structuredDelta = null,
        private(set) ?string $structuredPath = null,
        private(set) ?string $provider = null,
        private(set) ?string $model = null,
        private(set) ?int $attempt = null,
        private(set) ?int $maxAttempts = null,
        private(set) ?int $backoffMs = null,
        private(set) ?int $retryAfterSeconds = null,
        private(set) ?string $clientId = null,
        private(set) ?string $clientKind = null,
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
            return $this->stopReason !== StopReason::EndOfTurn
                && $this->stopReason !== StopReason::StopSequence
                && $this->stopReason !== StopReason::ToolUse;
        }

        if ($this->kind === ConversationTurnEventKind::InvocationCompleted) {
            return $this->stopReason !== StopReason::EndOfTurn
                && $this->stopReason !== StopReason::StopSequence
                && $this->stopReason !== StopReason::ToolUse;
        }

        return $this->kind !== ConversationTurnEventKind::ActivityCompleted
            && $this->kind !== ConversationTurnEventKind::ActivityStarted
            && $this->kind !== ConversationTurnEventKind::ArtifactDelta
            && $this->kind !== ConversationTurnEventKind::EffectArgumentsDelta
            && $this->kind !== ConversationTurnEventKind::InvocationStarted
            && $this->kind !== ConversationTurnEventKind::ProviderResolved
            && $this->kind !== ConversationTurnEventKind::RuntimeClientConnected
            && $this->kind !== ConversationTurnEventKind::RuntimeClientDisconnected
            && $this->kind !== ConversationTurnEventKind::StructuredDelta
            && $this->kind !== ConversationTurnEventKind::UsageDelta;
    }
}
