<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Projection\CollabProjector;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\LoopSlice;
use Phalanx\Theatron\Collab\State\MessageTimelineSlice;
use Phalanx\Theatron\Collab\State\ReviewSlice;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;

final class WorkContext
{
    public LoopStage $stage {
        get => $this->store->loop->stage;
    }

    public WorkPlan $plan {
        get => $this->store->workPlan->plan;
    }

    /** @var list<CollabEvent> */
    private array $projectedEvents = [];

    public function __construct(
        private(set) TaskScope $scope,
        private CollabStore $store = new CollabStore(),
    ) {
    }

    public function advance(LoopStage $stage): LoopSlice
    {
        return $this->store->mutate(
            LoopSlice::class,
            static fn(LoopSlice $slice): LoopSlice => $slice->advance($stage),
        );
    }

    public function project(CollabEvent $event): CollabStore
    {
        (new CollabProjector())->project($event, $this->store);
        $this->projectedEvents[] = $event;

        return $this->store;
    }

    /**
     * @return list<CollabEvent>
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
        $this->project(CollabEvent::record(EventKind::WorkReceived, envelope: $envelope));

        return $this->store->messages;
    }

    public function append(WorkItem ...$items): WorkPlanSlice
    {
        foreach ($items as $item) {
            $this->project(CollabEvent::record(EventKind::WorkPrepared, workItem: $item));
        }

        return $this->store->workPlan;
    }

    public function start(string $itemId): WorkPlanSlice
    {
        $item = $this->store->workPlan->plan->item($itemId);
        $this->project(CollabEvent::record(EventKind::WorkItemStarted, workItem: $item->workItem));

        return $this->store->workPlan;
    }

    public function fulfill(string $itemId, WorkResult $result): WorkPlanSlice
    {
        if ($itemId !== $result->itemId) {
            throw new \InvalidArgumentException('Work result item id must match the fulfilled item.');
        }

        $item = $this->store->workPlan->plan->item($itemId);
        $this->project(CollabEvent::record(
            $result->isDone() ? EventKind::WorkItemCompleted : EventKind::WorkInterrupted,
            workItem: $item->workItem,
            workResult: $result,
        ));

        return $this->store->workPlan;
    }

    public function abort(string $reason): WorkPlanSlice
    {
        $this->project(CollabEvent::record(
            EventKind::WorkReviewed,
            reviewVerdict: ReviewVerdict::reject($reason),
        ));

        return $this->store->workPlan;
    }

    public function review(ReviewVerdict $verdict): ReviewSlice
    {
        $this->project(CollabEvent::record(
            EventKind::WorkReviewed,
            reviewVerdict: $verdict,
        ));

        return $this->store->reviews;
    }
}
