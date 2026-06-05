<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Reviews;

use Phalanx\Tui\Collab\Plans\WorkItem;

final class ReviewVerdict
{
    /** @var list<WorkItem> */
    private(set) array $requiredWork;

    /**
     * @param list<WorkItem> $requiredWork
     */
    private function __construct(
        private(set) ReviewStatus $status,
        private(set) ?string $reason = null,
        array $requiredWork = [],
    ) {
        if ($this->status !== ReviewStatus::Approved && trim((string) $this->reason) === '') {
            throw new \InvalidArgumentException('Review verdict reason is required unless approved.');
        }

        if ($this->status === ReviewStatus::NeedsRevision && $requiredWork === []) {
            throw new \InvalidArgumentException('Revision verdict requires follow-up work.');
        }

        $this->requiredWork = array_values($requiredWork);
    }

    public static function approve(): self
    {
        return new self(ReviewStatus::Approved);
    }

    /**
     * @param list<WorkItem> $requiredWork
     */
    public static function reject(string $reason, array $requiredWork = []): self
    {
        return new self(ReviewStatus::Rejected, $reason, $requiredWork);
    }

    /**
     * @param list<WorkItem> $requiredWork
     */
    public static function revise(string $reason, array $requiredWork): self
    {
        return new self(ReviewStatus::NeedsRevision, $reason, $requiredWork);
    }

    public function isApproved(): bool
    {
        return $this->status === ReviewStatus::Approved;
    }

    public function isRejected(): bool
    {
        return $this->status === ReviewStatus::Rejected;
    }

    public function needsRevision(): bool
    {
        return $this->status === ReviewStatus::NeedsRevision;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'required_work' => array_map(
                static fn (WorkItem $item): array => $item->toCanonical(),
                $this->requiredWork,
            ),
        ];
    }
}
