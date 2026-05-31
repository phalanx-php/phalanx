<?php

declare(strict_types=1);

namespace Phalanx\Harness\Work;

use Phalanx\Harness\Event\HarnessEvent;
use Phalanx\Harness\Message\Envelope;

final class WorkPlanItem
{
    private(set) WorkItemStatus $status = WorkItemStatus::Pending;

    private(set) ?WorkResult $result = null;

    private(set) ?string $blockedReason = null;

    private(set) ?string $supersededBy = null;

    private(set) ?string $supersededReason = null;

    private(set) Envelope|HarnessEvent|null $resolvedBy = null;

    private function __construct(
        private(set) WorkItem $workItem,
    ) {
    }

    public static function pending(WorkItem $workItem): self
    {
        return new self($workItem);
    }

    public function start(): self
    {
        $this->assertStatus(WorkItemStatus::Pending, 'Only pending work can start.');

        $item = clone $this;
        $item->status = WorkItemStatus::Running;
        $item->clearResolutionState();

        return $item;
    }

    public function done(WorkResult $result): self
    {
        $this->assertStatus(WorkItemStatus::Running, 'Only running work can complete.');
        if (!$result->isDone()) {
            throw new \InvalidArgumentException('Done work requires a done result.');
        }

        $item = clone $this;
        $item->status = WorkItemStatus::Done;
        $item->result = $result;
        $item->clearResolutionState();

        return $item;
    }

    public function block(string $reason): self
    {
        if (!in_array($this->status, [WorkItemStatus::Pending, WorkItemStatus::Running], true)) {
            throw new \LogicException('Only pending or running work can be blocked.');
        }

        $item = clone $this;
        $item->status = WorkItemStatus::Blocked;
        $item->result = null;
        $item->blockedReason = self::requireReason($reason, 'Blocked work reason cannot be empty.');
        $item->resolvedBy = null;

        return $item;
    }

    public function unblock(Envelope|HarnessEvent|null $resolvedBy = null): self
    {
        $this->assertStatus(WorkItemStatus::Blocked, 'Only blocked work can be unblocked.');

        $item = clone $this;
        $item->status = WorkItemStatus::Pending;
        $item->blockedReason = null;
        $item->resolvedBy = $resolvedBy;

        return $item;
    }

    public function fail(WorkResult $result): self
    {
        $this->assertStatus(WorkItemStatus::Running, 'Only running work can fail.');
        if (!$result->isFailed()) {
            throw new \InvalidArgumentException('Failed work requires a failed result.');
        }

        $item = clone $this;
        $item->status = WorkItemStatus::Failed;
        $item->result = $result;
        $item->clearResolutionState();

        return $item;
    }

    public function supersede(string $replacementId, string $reason): self
    {
        if (in_array($this->status, [WorkItemStatus::Done, WorkItemStatus::Running, WorkItemStatus::Superseded], true)) {
            throw new \LogicException('Done, running, or already-superseded work cannot be superseded.');
        }

        $item = clone $this;
        $item->status = WorkItemStatus::Superseded;
        $item->supersededBy = self::requireReason($replacementId, 'Superseded work replacement id cannot be empty.');
        $item->supersededReason = self::requireReason($reason, 'Superseded work reason cannot be empty.');
        $item->blockedReason = null;
        $item->resolvedBy = null;

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'work_item' => $this->workItem->toCanonical(),
            'status' => $this->status,
            'result' => $this->result?->toCanonical(),
            'blocked_reason' => $this->blockedReason,
            'superseded_by' => $this->supersededBy,
            'superseded_reason' => $this->supersededReason,
            'resolved_by' => $this->resolvedBy?->toCanonical(),
        ];
    }

    private static function requireReason(string $reason, string $message): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \InvalidArgumentException($message);
        }

        return $reason;
    }

    private function assertStatus(WorkItemStatus $status, string $message): void
    {
        if ($this->status !== $status) {
            throw new \LogicException($message);
        }
    }

    private function clearResolutionState(): void
    {
        $this->blockedReason = null;
        $this->supersededBy = null;
        $this->supersededReason = null;
        $this->resolvedBy = null;
    }
}
