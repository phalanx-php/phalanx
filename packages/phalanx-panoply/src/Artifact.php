<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

use Phalanx\Panoply\Hash\Canonicalizable;

/**
 * Durable agent output. Follows a three-phase lifecycle: draft (empty
 * content, no hash, no finalized timestamp), in-progress (content set
 * via {@see self::withContent()}, hash still empty), and finalized (full
 * content + SHA-256 hash set via {@see self::finalize()}).
 *
 * All mutating operations return a new instance — the value object is
 * effectively immutable from the caller's perspective.
 *
 * The canonical hash returned by {@see self::toCanonical()} is a
 * **state fingerprint**, not a stable identity. It encodes content_hash,
 * updatedAt, and finalizedAt, so it changes across lifecycle transitions
 * even when id and agentId are identical. Use $artifact->id for stable
 * identity; use Canonical::of($artifact) for change detection and
 * replay-deduplication only.
 *
 * `final` because subclassing would alter {@see self::toCanonical()} and
 * break Canonical hash stability.
 */
final class Artifact implements Canonicalizable
{
    private function __construct(
        private(set) string $id,
        private(set) Artifact\Kind $kind,
        private(set) ?string $title,
        private(set) string $content,
        private(set) string $contentHash,
        private(set) string $agentId,
        private(set) string $activityId,
        private(set) \DateTimeImmutable $createdAt,
        private(set) ?\DateTimeImmutable $updatedAt,
        private(set) ?\DateTimeImmutable $finalizedAt,
    ) {
    }

    public static function draft(
        string $id,
        Artifact\Kind $kind,
        string $agentId,
        string $activityId = '',
        ?string $title = null,
        ?\DateTimeImmutable $createdAt = null,
    ): self {
        return new self(
            id: $id,
            kind: $kind,
            title: $title,
            content: '',
            contentHash: '',
            agentId: $agentId,
            activityId: $activityId,
            createdAt: $createdAt ?? new \DateTimeImmutable(),
            updatedAt: null,
            finalizedAt: null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'id'           => $this->id,
            'kind'         => $this->kind->value,
            'title'        => $this->title,
            'content'      => $this->content,
            'content_hash' => $this->contentHash,
            'agent_id'     => $this->agentId,
            'activity_id'  => $this->activityId,
            'created_at'   => $this->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'updated_at'   => $this->updatedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'finalized_at' => $this->finalizedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    public function withContent(string $content, ?\DateTimeImmutable $at = null): self
    {
        return new self(
            id: $this->id,
            kind: $this->kind,
            title: $this->title,
            content: $content,
            contentHash: '',
            agentId: $this->agentId,
            activityId: $this->activityId,
            createdAt: $this->createdAt,
            updatedAt: $at ?? new \DateTimeImmutable(),
            finalizedAt: null,
        );
    }

    public function finalize(string $content, string $contentHash, ?\DateTimeImmutable $at = null): self
    {
        return new self(
            id: $this->id,
            kind: $this->kind,
            title: $this->title,
            content: $content,
            contentHash: $contentHash,
            agentId: $this->agentId,
            activityId: $this->activityId,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            finalizedAt: $at ?? new \DateTimeImmutable(),
        );
    }

    public function isFinalized(): bool
    {
        return $this->finalizedAt !== null;
    }
}
