<?php

declare(strict_types=1);

namespace Phalanx\AiProviders;

use Phalanx\AiProviders\Hash\Canonicalizable;

/**
 * A single declared effect request. Carries the effect's ULID, kind,
 * human-readable summary, arbitrary arguments, approval flag, and an
 * optional hazard rating assigned by Hazard\Scorer.
 *
 * Distinct from {@see Effects}, which is the agent-level declaration of
 * the full set of permitted effect kinds. This class represents one
 * concrete effect instance within an execution.
 *
 * `final` because subclassing would alter {@see self::toCanonical()} and
 * break Canonical hash stability.
 */
final class Effect implements Canonicalizable
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private(set) string $id,
        private(set) Effect\Kind $kind,
        private(set) string $summary,
        private(set) array $arguments = [],
        private(set) bool $requiresApproval = false,
        private(set) ?Hazard $hazard = null,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function of(
        string $id,
        Effect\Kind $kind,
        string $summary,
        array $arguments = [],
        bool $requiresApproval = false,
        ?Hazard $hazard = null,
    ): self {
        return new self(
            id: $id,
            kind: $kind,
            summary: $summary,
            arguments: $arguments,
            requiresApproval: $requiresApproval,
            hazard: $hazard,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'summary' => $this->summary,
            'arguments' => $this->arguments,
            'requires_approval' => $this->requiresApproval,
            'hazard' => $this->hazard?->value,
        ];
    }

    public function withHazard(Hazard $hazard): self
    {
        return new self(
            id: $this->id,
            kind: $this->kind,
            summary: $this->summary,
            arguments: $this->arguments,
            requiresApproval: $this->requiresApproval,
            hazard: $hazard,
        );
    }
}
