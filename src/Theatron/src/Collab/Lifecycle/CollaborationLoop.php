<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Lifecycle;

use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Participants\Collaborator;
use Phalanx\Theatron\Collab\Participants\Preparer;
use Phalanx\Theatron\Collab\Participants\Reactor;
use Phalanx\Theatron\Collab\Participants\Reviewer;
use Phalanx\Theatron\Collab\Plans\WorkItemStatus;
use Phalanx\Theatron\Collab\Plans\WorkPlanItem;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\WorkContext;

final class CollaborationLoop
{
    /** @var list<Preparer> */
    private array $preparers;

    /** @var list<Collaborator> */
    private array $collaborators;

    /** @var list<Reactor> */
    private array $reactors;

    /** @var list<Reviewer> */
    private array $reviewers;

    /** @var array<string, true> */
    private array $emittedWorkItemIds = [];

    /**
     * @param iterable<Preparer> $preparers
     * @param iterable<Collaborator> $collaborators
     * @param iterable<Reactor> $reactors
     * @param iterable<Reviewer> $reviewers
     */
    public function __construct(
        private Collaborator $primary,
        iterable $preparers = [],
        iterable $collaborators = [],
        iterable $reactors = [],
        iterable $reviewers = [],
        private int $maxReviewPasses = 8,
    ) {
        if ($this->maxReviewPasses < 1) {
            throw new \InvalidArgumentException('Collaboration loop max review passes must be >= 1.');
        }

        $this->preparers = self::preparers($preparers);
        $this->collaborators = self::collaborators($collaborators);
        $this->reactors = self::reactors($reactors);
        $this->reviewers = self::reviewers($reviewers);
    }

    public function __invoke(WorkContext $ctx): WorkPlanStatus
    {
        $this->emittedWorkItemIds = [];

        $this->receive($ctx);
        $this->prepare($ctx);

        $reviewPasses = 0;
        while (true) {
            $status = $this->collaborate($ctx);
            if ($status !== WorkPlanStatus::Complete) {
                return $status;
            }

            $verdict = $this->review($ctx);
            if ($verdict->isRejected()) {
                return $ctx->plan->status;
            }

            if ($verdict->needsRevision()) {
                $reviewPasses++;
                if ($reviewPasses > $this->maxReviewPasses) {
                    throw new \LogicException('Collaboration loop exceeded the maximum review passes.');
                }

                continue;
            }

            $this->projectAndEmit($ctx, CollabEvent::record(EventKind::WorkCompleted));

            return $ctx->plan->status;
        }
    }

    private static function isStillReady(WorkContext $ctx, WorkPlanItem $item): bool
    {
        $current = $ctx->plan->item($item->workItem->id);
        if ($current->status !== WorkItemStatus::Pending) {
            return false;
        }

        $readyIds = array_map(static fn(WorkPlanItem $ready): string => $ready->workItem->id, $ctx->plan->readyItems());

        return in_array($item->workItem->id, $readyIds, true);
    }

    /**
     * @param iterable<Preparer> $preparers
     * @return list<Preparer>
     */
    private static function preparers(iterable $preparers): array
    {
        return self::instances($preparers, Preparer::class);
    }

    /**
     * @param iterable<Collaborator> $collaborators
     * @return list<Collaborator>
     */
    private static function collaborators(iterable $collaborators): array
    {
        return self::instances($collaborators, Collaborator::class);
    }

    /**
     * @param iterable<Reactor> $reactors
     * @return list<Reactor>
     */
    private static function reactors(iterable $reactors): array
    {
        return self::instances($reactors, Reactor::class);
    }

    /**
     * @param iterable<Reviewer> $reviewers
     * @return list<Reviewer>
     */
    private static function reviewers(iterable $reviewers): array
    {
        return self::instances($reviewers, Reviewer::class);
    }

    /**
     * @template T of object
     * @param iterable<object> $items
     * @param class-string<T> $type
     * @return list<T>
     */
    private static function instances(iterable $items, string $type): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!$item instanceof $type) {
                throw new \InvalidArgumentException(sprintf('Expected instances of %s.', $type));
            }

            $out[] = $item;
        }

        return $out;
    }

    private function receive(WorkContext $ctx): void
    {
        $received = $ctx->drainProjectedEvents(EventKind::WorkReceived);
        if ($received === []) {
            $this->projectAndEmit($ctx, CollabEvent::record(EventKind::WorkReceived));

            return;
        }

        foreach ($received as $event) {
            $this->rememberWorkItem($event);
            $this->emit($ctx, $event);
        }
    }

    private function prepare(WorkContext $ctx): void
    {
        $ctx->advance(LoopStage::Prepare);
        foreach ($this->preparers as $preparer) {
            $preparer($ctx);
        }

        $prepared = $ctx->drainProjectedEvents(EventKind::WorkPrepared);
        foreach ($prepared as $event) {
            $this->rememberWorkItem($event);
        }

        $prepared = [
            ...$prepared,
            ...$this->preseededWorkEvents($ctx),
        ];

        if ($prepared === []) {
            $this->projectAndEmit($ctx, CollabEvent::record(EventKind::WorkPrepared));

            return;
        }

        foreach ($prepared as $event) {
            $this->rememberWorkItem($event);
            $this->emit($ctx, $event);
        }
    }

    private function collaborate(WorkContext $ctx): WorkPlanStatus
    {
        while ($ctx->plan->status === WorkPlanStatus::Active) {
            $ready = $ctx->plan->readyItems();
            if ($ready === []) {
                return $ctx->plan->status;
            }

            $this->projectAndEmit($ctx, CollabEvent::record(EventKind::WorkDistributed));

            foreach ($ready as $item) {
                $this->collaborateOn($ctx, $item);
                if ($ctx->plan->status !== WorkPlanStatus::Active) {
                    break;
                }
            }
        }

        return $ctx->plan->status;
    }

    private function collaborateOn(WorkContext $ctx, WorkPlanItem $item): void
    {
        if (!self::isStillReady($ctx, $item)) {
            return;
        }

        $collaborator = $this->selectCollaborator($item, $ctx);

        $this->projectAndEmit($ctx, CollabEvent::record(EventKind::WorkItemStarted, workItem: $item->workItem));

        $running = $ctx->plan->item($item->workItem->id);
        try {
            $result = $collaborator($running, $ctx);
        } catch (\Phalanx\Cancellation\Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            $result = WorkResult::failed($item->workItem->id, $error);
        }

        $kind = $result->isDone() ? EventKind::WorkItemCompleted : EventKind::WorkInterrupted;
        $this->projectAndEmit($ctx, CollabEvent::record($kind, workItem: $item->workItem, workResult: $result));
    }

    private function selectCollaborator(WorkPlanItem $item, WorkContext $ctx): Collaborator
    {
        foreach ($this->collaborators as $collaborator) {
            if ($collaborator->supports($item, $ctx)) {
                return $collaborator;
            }
        }

        if (!$this->primary->supports($item, $ctx)) {
            throw new \LogicException(sprintf('No collaborator supports work item "%s".', $item->workItem->id));
        }

        return $this->primary;
    }

    private function review(WorkContext $ctx): ReviewVerdict
    {
        $ctx->advance(LoopStage::Review);
        if ($this->reviewers === []) {
            $verdict = ReviewVerdict::approve();
            $this->projectAndEmit($ctx, CollabEvent::record(EventKind::WorkReviewed, reviewVerdict: $verdict));

            return $verdict;
        }

        foreach ($this->reviewers as $reviewer) {
            $verdict = $reviewer($ctx);
            $this->projectAndEmit($ctx, CollabEvent::record(EventKind::WorkReviewed, reviewVerdict: $verdict));

            if (!$verdict->isApproved()) {
                return $verdict;
            }
        }

        return ReviewVerdict::approve();
    }

    private function projectAndEmit(WorkContext $ctx, CollabEvent $event): void
    {
        $ctx->project($event, queue: false);
        $this->rememberWorkItem($event);
        $this->emit($ctx, $event);
    }

    /**
     * @return list<CollabEvent>
     */
    private function preseededWorkEvents(WorkContext $ctx): array
    {
        $events = [];
        foreach ($ctx->plan->items() as $item) {
            if (isset($this->emittedWorkItemIds[$item->workItem->id])) {
                continue;
            }

            $events[] = CollabEvent::record(EventKind::WorkPrepared, workItem: $item->workItem);
        }

        return $events;
    }

    private function rememberWorkItem(CollabEvent $event): void
    {
        if ($event->workItem !== null) {
            $this->emittedWorkItemIds[$event->workItem->id] = true;
        }
    }

    private function emit(WorkContext $ctx, CollabEvent $event): void
    {
        $previous = $ctx->stage;
        $ctx->advance(LoopStage::React);

        try {
            foreach ($this->reactors as $reactor) {
                $reactor($event, $ctx);
            }
        } finally {
            $ctx->advance($previous);
        }
    }
}
