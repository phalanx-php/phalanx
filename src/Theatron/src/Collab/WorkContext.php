<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\LoopSlice;
use Phalanx\Theatron\Collab\State\MessageTimelineSlice;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;

final class WorkContext
{
    public LoopStage $stage {
        get => $this->store->loop->stage;
    }

    public WorkPlan $plan {
        get => $this->store->workPlan->plan;
    }

    public function __construct(
        private(set) ExecutionScope $scope,
        private(set) CollabStore $store = new CollabStore(),
    ) {
    }

    public function advance(LoopStage $stage): LoopSlice
    {
        return $this->store->mutate(
            LoopSlice::class,
            static fn(LoopSlice $slice): LoopSlice => $slice->advance($stage),
        );
    }

    public function record(Envelope $envelope): MessageTimelineSlice
    {
        return $this->store->mutate(
            MessageTimelineSlice::class,
            static fn(MessageTimelineSlice $slice): MessageTimelineSlice => $slice->record($envelope),
        );
    }

    public function fulfill(string $itemId, WorkResult $result): WorkPlanSlice
    {
        if ($itemId !== $result->itemId) {
            throw new \InvalidArgumentException('Work result item id must match the fulfilled item.');
        }

        $slice = $this->store->mutate(
            WorkPlanSlice::class,
            static fn(WorkPlanSlice $slice): WorkPlanSlice => $slice->fulfill($result),
        );

        foreach ($result->envelopes as $envelope) {
            $this->record($envelope);
        }

        return $slice;
    }
}
