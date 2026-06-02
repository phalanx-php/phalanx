<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Plans;

use Phalanx\Theatron\Collab\Events\AgentHarnessEvent;
use Phalanx\Theatron\Collab\Messages\Envelope;

final class WorkPlanItem
{
    private function __construct(
        private(set) WorkItem $workItem,
        private(set) WorkItemStatus $status,
        private(set) ?WorkResult $result = null,
        private(set) ?string $blockedReason = null,
        private(set) ?string $supersededBy = null,
        private(set) ?string $supersededReason = null,
        private(set) Envelope|AgentHarnessEvent|null $resolvedBy = null,
    ) {
    }

    public static function pending(WorkItem $workItem): self
    {
        return new self(
            workItem: $workItem,
            status: WorkItemStatus::Pending,
        );
    }

    public static function running(self $item): self
    {
        $item->assertStatus(WorkItemStatus::Pending, 'Only pending work can start.');

        return new self(
            workItem: $item->workItem,
            status: WorkItemStatus::Running,
        );
    }

    public static function done(self $item, WorkResult $result): self
    {
        $item->assertStatus(WorkItemStatus::Running, 'Only running work can complete.');
        $item->assertResultOwnership($result);
        if (!$result->isDone()) {
            throw new \InvalidArgumentException('Done work requires a done result.');
        }

        return new self(
            workItem: $item->workItem,
            status: WorkItemStatus::Done,
            result: $result,
        );
    }

    public static function blocked(self $item, string $reason): self
    {
        if (!in_array($item->status, [WorkItemStatus::Pending, WorkItemStatus::Running], true)) {
            throw new \LogicException('Only pending or running work can be blocked.');
        }

        return new self(
            workItem: $item->workItem,
            status: WorkItemStatus::Blocked,
            blockedReason: self::requireReason($reason, 'Blocked work reason cannot be empty.'),
        );
    }

    public static function unblocked(self $item, Envelope|AgentHarnessEvent|null $resolvedBy = null): self
    {
        $item->assertStatus(WorkItemStatus::Blocked, 'Only blocked work can be unblocked.');

        return new self(
            workItem: $item->workItem,
            status: WorkItemStatus::Pending,
            resolvedBy: $resolvedBy,
        );
    }

    public static function failed(self $item, WorkResult $result): self
    {
        $item->assertStatus(WorkItemStatus::Running, 'Only running work can fail.');
        $item->assertResultOwnership($result);
        if (!$result->isFailed()) {
            throw new \InvalidArgumentException('Failed work requires a failed result.');
        }

        return new self(
            workItem: $item->workItem,
            status: WorkItemStatus::Failed,
            result: $result,
        );
    }

    public static function superseded(self $item, string $replacementId, string $reason): self
    {
        if (in_array($item->status, [WorkItemStatus::Done, WorkItemStatus::Running, WorkItemStatus::Superseded], true)) {
            throw new \LogicException('Done, running, or already-superseded work cannot be superseded.');
        }

        return new self(
            workItem: $item->workItem,
            status: WorkItemStatus::Superseded,
            supersededBy: self::requireReason($replacementId, 'Superseded work replacement id cannot be empty.'),
            supersededReason: self::requireReason($reason, 'Superseded work reason cannot be empty.'),
        );
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

    private function assertResultOwnership(WorkResult $result): void
    {
        if ($result->itemId !== $this->workItem->id) {
            throw new \InvalidArgumentException('Work result item id must match the plan item.');
        }
    }
}
