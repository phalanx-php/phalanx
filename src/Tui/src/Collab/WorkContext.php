<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Collab\Events\Event;
use Phalanx\Tui\Collab\Events\EventKind;
use Phalanx\Tui\Collab\Lifecycle\LoopStage;
use Phalanx\Tui\Collab\Messages\Envelope;
use Phalanx\Tui\Collab\Plans\WorkItem;
use Phalanx\Tui\Collab\Plans\WorkPlan;
use Phalanx\Tui\Collab\Plans\WorkResult;
use Phalanx\Tui\Collab\Projection\Projector;
use Phalanx\Tui\Collab\Reviews\ReviewVerdict;
use Phalanx\Tui\Collab\State\LoopSlice;
use Phalanx\Tui\Collab\State\MessageTimelineSlice;
use Phalanx\Tui\Collab\State\ReviewSlice;
use Phalanx\Tui\Collab\State\Store;
use Phalanx\Tui\Collab\State\WorkPlanSlice;

final class WorkContext
{
    public LoopStage $stage {
        get => $this->store->loop->stage;
    }

    public WorkPlan $plan {
        get => $this->store->workPlan->plan;
    }

    /** @var list<Event> */
    private array $projectedEvents = [];

    public function __construct(
        private(set) TaskScope $scope,
        private Store $store = new Store(),
    ) {
    }

    public function advance(LoopStage $stage): LoopSlice
    {
        $this->project(Event::record(
            EventKind::LoopAdvanced,
            context: ['loop_stage' => $stage->value],
        ), queue: false);

        return $this->store->loop;
    }

    public function project(Event $event, bool $queue = true): Store
    {
        (new Projector())->project($event, $this->store);
        if ($queue) {
            $this->projectedEvents[] = $event;
        }

        return $this->store;
    }

    /**
     * @return list<Event>
     */
    public function drainProjectedEvents(?EventKind $kind = null): array
    {
        $events = [];
        $remaining = [];

        foreach ($this->projectedEvents as $event) {
            if ($kind === null || $event->kind === $kind) {
                $events[] = $event;

                continue;
            }

            $remaining[] = $event;
        }

        $this->projectedEvents = $remaining;

        return $events;
    }

    public function record(Envelope $envelope): MessageTimelineSlice
    {
        $this->project(Event::record(EventKind::WorkReceived, envelope: $envelope));

        return $this->store->messages;
    }

    public function append(WorkItem ...$items): WorkPlanSlice
    {
        foreach ($items as $item) {
            $this->project(Event::record(EventKind::WorkPrepared, workItem: $item));
        }

        return $this->store->workPlan;
    }

    public function start(string $itemId): WorkPlanSlice
    {
        $item = $this->store->workPlan->plan->item($itemId);
        $this->project(Event::record(EventKind::WorkItemStarted, workItem: $item->workItem));

        return $this->store->workPlan;
    }

    public function fulfill(string $itemId, WorkResult $result): WorkPlanSlice
    {
        if ($itemId !== $result->itemId) {
            throw new \InvalidArgumentException('Work result item id must match the fulfilled item.');
        }

        $item = $this->store->workPlan->plan->item($itemId);
        $this->project(Event::record(
            $result->isDone() ? EventKind::WorkItemCompleted : EventKind::WorkInterrupted,
            workItem: $item->workItem,
            workResult: $result,
        ));

        return $this->store->workPlan;
    }

    public function abort(string $reason): WorkPlanSlice
    {
        $this->project(Event::record(
            EventKind::WorkReviewed,
            reviewVerdict: ReviewVerdict::reject($reason),
        ));

        return $this->store->workPlan;
    }

    public function review(ReviewVerdict $verdict): ReviewSlice
    {
        $this->project(Event::record(
            EventKind::WorkReviewed,
            reviewVerdict: $verdict,
        ));

        return $this->store->reviews;
    }
}
