<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Canonical request shape for one model call. Carries everything that
 * uniquely identifies the call — agent identity, activity boundary,
 * assembled context's hash, instructions, output declaration, effect
 * surface, transport requirements, and creation timestamp.
 *
 * The Invocation's own hash (`Hash\Canonical::of($invocation)`) is the
 * canonical `prompt_hash` — stable across PHP runs, key orderings, and
 * platforms. Suitable for replay keys, cache keys, audit IDs, and
 * provider-debugging fingerprints.
 *
 * Construct via {@see self::of()} which fills `createdAt` and lets the
 * agent runtime derive `contextHash` from the assembled context envelope.
 *
 * Final because the canonical hash is load-bearing: subclassing would
 * alter toCanonical() and break hash stability across consumers.
 */
final class Invocation implements Canonicalizable
{
    /**
     * @param array<string, mixed> $dynamicContext
     */
    public function __construct(
        private(set) string $id,
        private(set) string $agentId,
        private(set) string $activityId,
        private(set) string $contextHash,
        private(set) string $instructions,
        private(set) Output $output,
        private(set) Effects $effects,
        private(set) Provider\Needs $provider,
        private(set) Transport\Needs $transport,
        private(set) array $dynamicContext,
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $dynamicContext
     */
    public static function of(
        string $id,
        string $agentId,
        string $activityId,
        string $contextHash,
        string $instructions,
        Output $output,
        Effects $effects,
        Provider\Needs $provider,
        Transport\Needs $transport,
        array $dynamicContext = [],
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            id: $id,
            agentId: $agentId,
            activityId: $activityId,
            contextHash: $contextHash,
            instructions: $instructions,
            output: $output,
            effects: $effects,
            provider: $provider,
            transport: $transport,
            dynamicContext: $dynamicContext,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agentId,
            'activity_id' => $this->activityId,
            'context_hash' => $this->contextHash,
            'instructions' => $this->instructions,
            'output' => $this->output->toCanonical(),
            'effects' => $this->effects->toCanonical(),
            'provider' => $this->provider->toCanonical(),
            'transport' => $this->transport->toCanonical(),
            'dynamic_context' => $this->dynamicContext,
            // Normalize to UTC and emit microsecond precision so hashes
            // match across hosts in any timezone.
            'created_at' => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /**
     * The canonical prompt hash for this invocation. Stable across PHP
     * runs, key orderings, and host platforms.
     */
    public function promptHash(): string
    {
        return Canonical::of($this);
    }
}
