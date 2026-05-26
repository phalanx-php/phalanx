<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

final class EffectLogEntry
{
    /**
     * @param array<string, mixed> $arguments
     * @param list<string> $reasonCodes
     */
    public function __construct(
        private(set) string $effectId,
        private(set) string $activityId,
        private(set) ?string $invocationId,
        private(set) ?string $agentId,
        private(set) string $kind,
        private(set) string $summary,
        private(set) string $hazard,
        private(set) array $arguments = [],
        private(set) EffectStatus $status = EffectStatus::Requested,
        private(set) array $reasonCodes = [],
        private(set) ?string $grantId = null,
        private(set) ?int $durationMs = null,
        private(set) ?string $errorClass = null,
    ) {
    }

    public static function requested(PendingEffect $effect): self
    {
        return new self(
            effectId: $effect->effectId,
            activityId: $effect->activityId,
            invocationId: $effect->invocationId,
            agentId: $effect->agentId,
            kind: $effect->kind,
            summary: $effect->summary,
            hazard: $effect->hazard,
            arguments: $effect->arguments,
        );
    }

    /** @param list<string> $reasonCodes */
    public function withStatus(
        EffectStatus $status,
        array $reasonCodes = [],
        ?string $grantId = null,
        ?int $durationMs = null,
        ?string $errorClass = null,
    ): self {
        return new self(
            effectId: $this->effectId,
            activityId: $this->activityId,
            invocationId: $this->invocationId,
            agentId: $this->agentId,
            kind: $this->kind,
            summary: $this->summary,
            hazard: $this->hazard,
            arguments: $this->arguments,
            status: $status,
            reasonCodes: $reasonCodes,
            grantId: $this->grantIdFor($status, $grantId),
            durationMs: $status === EffectStatus::Executed ? $durationMs : null,
            errorClass: $status === EffectStatus::Failed ? $errorClass : null,
        );
    }

    private function grantIdFor(EffectStatus $status, ?string $grantId): ?string
    {
        return match ($status) {
            EffectStatus::Approved,
            EffectStatus::Denied,
            EffectStatus::Executed,
            EffectStatus::Failed => $grantId ?? $this->grantId,
            EffectStatus::Paused,
            EffectStatus::Requested => null,
        };
    }
}
