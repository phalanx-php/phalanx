<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Lifecycle;

use Phalanx\Tui\Collab\Events\Event;
use Phalanx\Tui\Collab\Events\EventKind;
use Phalanx\Tui\Collab\Participants\AgentParticipant;
use Phalanx\Tui\Collab\Participants\Preparer;
use Phalanx\Tui\Collab\Participants\Reactor;
use Phalanx\Tui\Collab\Participants\Reviewer;
use Phalanx\Tui\Collab\Plans\WorkItemStatus;
use Phalanx\Tui\Collab\Plans\WorkPlanItem;
use Phalanx\Tui\Collab\Plans\WorkPlanStatus;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Reviews\ReviewVerdict;
use Phalanx\Tui\Collab\WorkContext;

final class Loop
{
    /** @var list<Preparer> */
    private array $preparers;

    /** @var list<Reactor> */
    private array $reactors;

    /** @var list<Reviewer> */
    private array $reviewers;
    
    /** @var list<AgentParticipant> */
    private array $participants;

    /** @var array<string, true> */
    private array $emittedWorkItemIds = [];

    /**
     * @param iterable<Preparer> $preparers
     * @param iterable<AgentParticipant> $participants
     * @param iterable<Reactor> $reactors
     * @param iterable<Reviewer> $reviewers
     */
    public function __construct(
        private AgentParticipant $primary,
        iterable $preparers = [],
        iterable $participants = [],
        iterable $reactors = [],
        iterable $reviewers = [],
        private int $maxReviewPasses = 8,
    ) {
        if ($this->maxReviewPasses < 1) {
            throw new \InvalidArgumentException('Collab loop max review passes must be >= 1.');
        }

        $this->reactors = self::instances($reactors, Reactor::class);
        $this->preparers = self::instances($preparers, Preparer::class);
        $this->reviewers = self::instances($reviewers, Reviewer::class);
        $this->participants = self::instances($participants, AgentParticipant::class);
    }

    public function __invoke(WorkContext $ctx): WorkPlanStatus
    {
        $this->emittedWorkItemIds = [];

        $this->receive($ctx);
        $this->prepare($ctx);

        $reviewPasses = 0;
        while (true) {
            $status = $this->execute($ctx);
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
                    throw new \LogicException('Collab loop exceeded the maximum review passes.');
                }

                continue;
            }

            $this->projectAndEmit($ctx, Event::record(EventKind::WorkCompleted));

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
            $this->projectAndEmit($ctx, Event::record(EventKind::WorkReceived));

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
            $this->projectAndEmit($ctx, Event::record(EventKind::WorkPrepared));

            return;
        }

        foreach ($prepared as $event) {
            $this->rememberWorkItem($event);
            $this->emit($ctx, $event);
        }
    }

    private function execute(WorkContext $ctx): WorkPlanStatus
    {
        while ($ctx->plan->status === WorkPlanStatus::Active) {
            $ready = $ctx->plan->readyItems();
            if ($ready === []) {
                return $ctx->plan->status;
            }

            $this->projectAndEmit($ctx, Event::record(EventKind::WorkDistributed));

            foreach ($ready as $item) {
                $this->executeOn($ctx, $item);
                if ($ctx->plan->status !== WorkPlanStatus::Active) {
                    break;
                }
            }
        }

        return $ctx->plan->status;
    }

    private function executeOn(WorkContext $ctx, WorkPlanItem $item): void
    {
        if (!self::isStillReady($ctx, $item)) {
            return;
        }

        $participant = $this->selectAgentParticipant($item, $ctx);

        $this->projectAndEmit($ctx, Event::record(EventKind::WorkItemStarted, workItem: $item->workItem));

        $running = $ctx->plan->item($item->workItem->id);
        try {
            $result = $participant($running, $ctx);
        } catch (\Phalanx\Cancellation\Cancelled $cancelled) {
            throw $cancelled;
        } catch (\Throwable $error) {
            $result = WorkResult::failed($item->workItem->id, $error);
        }

        $kind = $result->isDone() ? EventKind::WorkItemCompleted : EventKind::WorkInterrupted;
        $this->projectAndEmit($ctx, Event::record($kind, workItem: $item->workItem, workResult: $result));
    }

    private function selectAgentParticipant(WorkPlanItem $item, WorkContext $ctx): AgentParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->supports($item, $ctx)) {
                return $participant;
            }
        }

        if (!$this->primary->supports($item, $ctx)) {
            throw new \LogicException(sprintf('No participant supports work item "%s".', $item->workItem->id));
        }

        return $this->primary;
    }

    private function review(WorkContext $ctx): ReviewVerdict
    {
        $ctx->advance(LoopStage::Review);
        if ($this->reviewers === []) {
            $verdict = ReviewVerdict::approve();
            $this->projectAndEmit($ctx, Event::record(EventKind::WorkReviewed, reviewVerdict: $verdict));

            return $verdict;
        }

        foreach ($this->reviewers as $reviewer) {
            $verdict = $reviewer($ctx);
            $this->projectAndEmit($ctx, Event::record(EventKind::WorkReviewed, reviewVerdict: $verdict));

            if (!$verdict->isApproved()) {
                return $verdict;
            }
        }

        return ReviewVerdict::approve();
    }

    private function projectAndEmit(WorkContext $ctx, Event $event): void
    {
        $ctx->project($event, queue: false);
        $this->rememberWorkItem($event);
        $this->emit($ctx, $event);
    }

    /**
     * @return list<Event>
     */
    private function preseededWorkEvents(WorkContext $ctx): array
    {
        $events = [];
        foreach ($ctx->plan->items() as $item) {
            if (isset($this->emittedWorkItemIds[$item->workItem->id])) {
                continue;
            }

            $events[] = Event::record(EventKind::WorkPrepared, workItem: $item->workItem);
        }

        return $events;
    }

    private function rememberWorkItem(Event $event): void
    {
        if ($event->workItem !== null) {
            $this->emittedWorkItemIds[$event->workItem->id] = true;
        }
    }

    private function emit(WorkContext $ctx, Event $event): void
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
