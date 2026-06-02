<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Plans;

use Phalanx\Theatron\Collab\Events\AgentHarnessEvent;
use Phalanx\Theatron\Collab\Internal\Id;
use Phalanx\Theatron\Collab\Messages\Envelope;

final class WorkPlan
{
    private(set) string $id;

    private(set) WorkPlanStatus $status = WorkPlanStatus::Active;

    private(set) ?string $statusReason = null;

    /** @var array<string, WorkPlanItem> */
    private array $items = [];

    /** @var list<string> */
    private array $order = [];

    private function __construct(
        ?string $id = null,
    ) {
        $this->id = $id === null ? Id::new('plan') : self::requireId($id, 'Work plan id cannot be empty.');
    }

    public static function empty(?string $id = null): self
    {
        return new self($id);
    }

    public static function start(WorkItem ...$items): self
    {
        $plan = new self();
        $plan->append(...$items);

        return $plan;
    }

    public function append(WorkItem ...$items): void
    {
        $this->assertAppendable();
        $items = array_values($items);
        $this->validateAppend($items);

        foreach ($items as $item) {
            $this->items[$item->id] = WorkPlanItem::pending($item);
            $this->order[] = $item->id;
        }

        $this->refreshStatus();
    }

    /**
     * @return list<WorkPlanItem>
     */
    public function items(): array
    {
        return array_map(fn (string $id): WorkPlanItem => $this->items[$id], $this->order);
    }

    public function item(string $itemId): WorkPlanItem
    {
        return $this->items[$this->requireKnownItem($itemId)];
    }

    /**
     * @return list<WorkPlanItem>
     */
    public function readyItems(): array
    {
        if ($this->status !== WorkPlanStatus::Active) {
            return [];
        }

        return $this->readyItemsForActivePlan();
    }

    public function startItem(string $itemId): void
    {
        $this->assertMutable();
        $item = $this->item($itemId);
        if (!in_array($item, $this->readyItems(), true)) {
            throw new \LogicException('Only ready work can start.');
        }

        $this->items[$item->workItem->id] = WorkPlanItem::running($item);
        $this->refreshStatus();
    }

    public function fulfill(WorkResult $result): void
    {
        $this->assertMutable();
        $item = $this->item($result->itemId);
        if ($item->status !== WorkItemStatus::Running) {
            throw new \LogicException('Only running work can be fulfilled.');
        }

        if ($result->isDone()) {
            $item = WorkPlanItem::done($item, $result);
        } elseif ($result->isBlocked()) {
            $item = WorkPlanItem::blocked($item, $result->summary ?? '');
        } elseif ($result->isFailed()) {
            $item = WorkPlanItem::failed($item, $result);
        }

        $this->items[$result->itemId] = $item;
        $this->refreshStatus();
    }

    public function block(string $itemId, string $reason): void
    {
        $this->assertMutable();
        $item = $this->item($itemId);
        if (!in_array($item->status, [WorkItemStatus::Pending, WorkItemStatus::Running], true)) {
            throw new \LogicException('Only pending or running work can be blocked.');
        }

        $this->items[$item->workItem->id] = WorkPlanItem::blocked($item, $reason);
        $this->refreshStatus();
    }

    public function unblock(string $itemId, Envelope|AgentHarnessEvent|null $resolvedBy = null): void
    {
        $this->assertMutable();
        $this->items[$this->requireKnownItem($itemId)] = WorkPlanItem::unblocked($this->item($itemId), $resolvedBy);
        $this->refreshStatus();
    }

    public function supersede(string $itemId, WorkItem $replacement, string $reason): void
    {
        $this->assertMutable();
        $item = $this->item($itemId);
        if (in_array($itemId, $replacement->dependsOn, true)) {
            throw new \InvalidArgumentException('Replacement work cannot depend on the work it supersedes.');
        }

        $this->assertReplacementChainIsOpen($itemId);
        $this->validateAppend([$replacement]);
        $this->assertEffectiveAcyclic([$itemId => $replacement->id], [$replacement]);
        $this->items[$itemId] = WorkPlanItem::superseded($item, $replacement->id, $reason);
        $this->items[$replacement->id] = WorkPlanItem::pending($replacement);
        $this->order[] = $replacement->id;
        $this->refreshStatus();
    }

    public function abort(string $reason): void
    {
        $this->assertAbortable();
        $this->status = WorkPlanStatus::Aborted;
        $this->statusReason = self::requireId($reason, 'Aborted work plan reason cannot be empty.');
    }

    public function isComplete(): bool
    {
        return $this->status === WorkPlanStatus::Complete;
    }

    /**
     * @return array<string, mixed>
     */
    public function toCanonical(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_reason' => $this->statusReason,
            'items' => array_map(
                static fn (WorkPlanItem $item): array => $item->toCanonical(),
                $this->items(),
            ),
        ];
    }

    private static function requireId(string $id, string $message): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException($message);
        }

        return $id;
    }

    /**
     * @param list<WorkItem> $items
     */
    private function validateAppend(array $items): void
    {
        if ($items === []) {
            return;
        }

        $known = array_fill_keys(array_keys($this->items), true);
        foreach ($items as $item) {
            if (isset($known[$item->id])) {
                throw new \InvalidArgumentException(sprintf('Work item "%s" already exists in this plan.', $item->id));
            }

            $known[$item->id] = true;
        }

        foreach ($items as $item) {
            foreach ($item->dependsOn as $dependencyId) {
                if (!isset($known[$dependencyId])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Work item "%s" depends on unknown item "%s".',
                        $item->id,
                        $dependencyId,
                    ));
                }
            }
        }

        $this->assertAcyclic($items);
    }

    /**
     * @param list<WorkItem> $items
     */
    private function assertAcyclic(array $items): void
    {
        $this->assertEffectiveAcyclic([], $items);
    }

    /**
     * @param array<string, string> $pendingSupersessions
     * @param list<WorkItem> $pendingItems
     */
    private function assertEffectiveAcyclic(array $pendingSupersessions, array $pendingItems): void
    {
        $graph = [];
        foreach ($this->items() as $item) {
            $graph[$item->workItem->id] = $item->workItem->dependsOn;
            if ($item->supersededBy !== null) {
                $graph[$item->workItem->id][] = $item->supersededBy;
            }
        }

        foreach ($pendingItems as $item) {
            $graph[$item->id] = $item->dependsOn;
        }

        foreach ($pendingSupersessions as $supersededId => $replacementId) {
            $graph[$supersededId][] = $replacementId;
        }

        $visiting = [];
        $visited = [];
        foreach (array_keys($graph) as $id) {
            $this->visitDependency($id, $graph, $visiting, $visited);
        }
    }

    /**
     * @param array<string, list<string>> $graph
     * @param array<string, true> $visiting
     * @param array<string, true> $visited
     */
    private function visitDependency(string $id, array $graph, array &$visiting, array &$visited): void
    {
        if (isset($visited[$id])) {
            return;
        }

        if (isset($visiting[$id])) {
            throw new \InvalidArgumentException('Work plan cannot contain dependency cycles.');
        }

        $visiting[$id] = true;
        foreach ($graph[$id] ?? [] as $dependencyId) {
            $this->visitDependency($dependencyId, $graph, $visiting, $visited);
        }

        unset($visiting[$id]);
        $visited[$id] = true;
    }

    /**
     * @return list<WorkPlanItem>
     */
    private function readyItemsForActivePlan(): array
    {
        $items = array_filter(
            $this->items(),
            fn (WorkPlanItem $item): bool => $item->status === WorkItemStatus::Pending
                && $this->dependenciesAreSatisfied($item),
        );

        usort(
            $items,
            fn (WorkPlanItem $left, WorkPlanItem $right): int => $right->workItem->priority <=> $left->workItem->priority
                ?: array_search($left->workItem->id, $this->order, true) <=> array_search($right->workItem->id, $this->order, true),
        );

        return $items;
    }

    private function dependenciesAreSatisfied(WorkPlanItem $item): bool
    {
        foreach ($this->effectiveDependencyIds($item) as $dependencyId) {
            if (!$this->dependencyIsSatisfied($dependencyId)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function effectiveDependencyIds(WorkPlanItem $item): array
    {
        $dependencies = $item->workItem->dependsOn;
        foreach ($this->items() as $candidate) {
            if ($candidate->supersededBy === $item->workItem->id) {
                array_push($dependencies, ...$this->effectiveDependencyIds($candidate));
            }
        }

        return array_values(array_unique($dependencies));
    }

    private function dependencyIsSatisfied(string $itemId): bool
    {
        $item = $this->items[$this->requireKnownItem($itemId)];

        if ($item->status === WorkItemStatus::Done) {
            return true;
        }

        if ($item->status === WorkItemStatus::Superseded && $item->supersededBy !== null) {
            return $this->dependencyIsSatisfied($item->supersededBy);
        }

        return false;
    }

    private function refreshStatus(): void
    {
        if ($this->status === WorkPlanStatus::Aborted) {
            return;
        }

        if ($this->allActivePathsAreDone()) {
            $this->status = WorkPlanStatus::Complete;
            $this->statusReason = null;

            return;
        }

        $criticalFailure = $this->firstActiveCriticalFailure();
        if ($criticalFailure !== null) {
            $this->status = WorkPlanStatus::Suspended;
            $this->statusReason = sprintf('Critical work item "%s" failed.', $criticalFailure->workItem->id);

            return;
        }

        if ($this->hasRunningItems() || $this->readyItemsForActivePlan() !== []) {
            $this->status = WorkPlanStatus::Active;
            $this->statusReason = null;

            return;
        }

        $this->status = WorkPlanStatus::Suspended;
        $this->statusReason = 'No runnable work remains while the plan is incomplete.';
    }

    private function allActivePathsAreDone(): bool
    {
        if ($this->items === []) {
            return true;
        }

        foreach ($this->items() as $item) {
            if ($item->status === WorkItemStatus::Superseded) {
                if ($item->supersededBy === null || !$this->dependencyIsSatisfied($item->supersededBy)) {
                    return false;
                }

                continue;
            }

            if ($item->status !== WorkItemStatus::Done) {
                return false;
            }
        }

        return true;
    }

    private function firstActiveCriticalFailure(): ?WorkPlanItem
    {
        foreach ($this->items() as $item) {
            if (
                $item->workItem->critical
                && $item->status === WorkItemStatus::Failed
            ) {
                return $item;
            }
        }

        return null;
    }

    private function hasRunningItems(): bool
    {
        foreach ($this->items() as $item) {
            if ($item->status === WorkItemStatus::Running) {
                return true;
            }
        }

        return false;
    }

    private function assertReplacementChainIsOpen(string $itemId): void
    {
        $item = $this->item($itemId);
        if (in_array($item->status, [WorkItemStatus::Done, WorkItemStatus::Running, WorkItemStatus::Superseded], true)) {
            throw new \LogicException('Done, running, or already-superseded work cannot be superseded.');
        }
    }

    private function requireKnownItem(string $itemId): string
    {
        $itemId = self::requireId($itemId, 'Work item id cannot be empty.');
        if (!isset($this->items[$itemId])) {
            throw new \InvalidArgumentException(sprintf('Unknown work item "%s".', $itemId));
        }

        return $itemId;
    }

    private function assertMutable(): void
    {
        if (in_array($this->status, [WorkPlanStatus::Complete, WorkPlanStatus::Aborted], true)) {
            throw new \LogicException('Complete or aborted work plans cannot be changed.');
        }
    }

    private function assertAppendable(): void
    {
        if ($this->status === WorkPlanStatus::Aborted) {
            throw new \LogicException('Aborted work plans cannot be changed.');
        }
    }

    private function assertAbortable(): void
    {
        if ($this->status === WorkPlanStatus::Aborted) {
            throw new \LogicException('Aborted work plans cannot be changed.');
        }
    }
}
