<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Projection;

use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\LoopSlice;
use Phalanx\Theatron\Collab\State\MessageTimelineSlice;
use Phalanx\Theatron\Collab\State\ReviewSlice;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;

final class CollabProjector
{
    public function __invoke(CollabEvent $event, CollabStore $store): CollabStore
    {
        $this->project($event, $store);

        return $store;
    }

    public function project(CollabEvent $event, CollabStore $store): CollabStore
    {
        $this->validate($event);

        $store->transaction(function () use ($event, $store): void {
            match ($event->kind) {
                EventKind::WorkReceived => $this->projectReceived($event, $store),
                EventKind::WorkPrepared,
                EventKind::WorkDistributed,
                EventKind::WorkCompleted => null,
                EventKind::WorkItemStarted => $this->projectStarted($event, $store),
                EventKind::WorkItemCompleted,
                EventKind::WorkInterrupted => $this->projectResult($event, $store),
                EventKind::WorkReviewed => $this->projectReview($event, $store),
                EventKind::EffectRequested,
                EventKind::EffectApproved,
                EventKind::EffectDenied => throw new \InvalidArgumentException(sprintf(
                    'Collab event "%s" is not supported by the alpha projector.',
                    $event->kind->value,
                )),
            };

            $this->projectStage($event, $store);
        });

        return $store;
    }

    private static function stageFor(EventKind $kind): LoopStage
    {
        return match ($kind) {
            EventKind::WorkReceived => LoopStage::Receive,
            EventKind::WorkPrepared => LoopStage::Prepare,
            EventKind::WorkDistributed => LoopStage::Distribute,
            EventKind::WorkItemStarted,
            EventKind::WorkItemCompleted,
            EventKind::WorkInterrupted => LoopStage::Collaborate,
            EventKind::EffectRequested,
            EventKind::EffectApproved,
            EventKind::EffectDenied => LoopStage::React,
            EventKind::WorkReviewed => LoopStage::Review,
            EventKind::WorkCompleted => LoopStage::Complete,
        };
    }

    private function validate(CollabEvent $event): void
    {
        match ($event->kind) {
            EventKind::WorkReceived => $this->validateReceived($event),
            EventKind::WorkPrepared,
            EventKind::WorkDistributed,
            EventKind::WorkCompleted => null,
            EventKind::WorkItemStarted => $this->requireWorkItem($event),
            EventKind::WorkItemCompleted,
            EventKind::WorkInterrupted => $this->validateResult($event),
            EventKind::WorkReviewed => $this->validateReview($event),
            EventKind::EffectRequested,
            EventKind::EffectApproved,
            EventKind::EffectDenied => throw new \InvalidArgumentException(sprintf(
                'Collab event "%s" is not supported by the alpha projector.',
                $event->kind->value,
            )),
        };
    }

    private function projectReceived(CollabEvent $event, CollabStore $store): void
    {
        if ($event->envelope === null && $event->workItem === null) {
            return;
        }

        $workItem = $this->requireWorkItem($event);

        $store->mutate(
            WorkPlanSlice::class,
            static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->append($workItem),
        );

        $store->mutate(
            MessageTimelineSlice::class,
            static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
        );
    }

    private function projectStarted(CollabEvent $event, CollabStore $store): void
    {
        $workItem = $this->requireWorkItem($event);

        $store->mutate(
            WorkPlanSlice::class,
            static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->start($workItem->id),
        );

        $store->mutate(
            MessageTimelineSlice::class,
            static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
        );
    }

    private function projectResult(CollabEvent $event, CollabStore $store): void
    {
        $workResult = $this->requireWorkResult($event);

        $store->mutate(
            WorkPlanSlice::class,
            static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->fulfill($workResult),
        );

        $store->mutate(
            MessageTimelineSlice::class,
            static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
        );
    }

    private function projectReview(CollabEvent $event, CollabStore $store): void
    {
        $verdict = $this->requireReviewVerdict($event);

        if ($verdict->needsRevision()) {
            $store->mutate(
                WorkPlanSlice::class,
                static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->append(...$verdict->requiredWork),
            );
        }

        if ($verdict->isRejected()) {
            $store->mutate(
                WorkPlanSlice::class,
                static fn (WorkPlanSlice $slice): WorkPlanSlice => $slice->abort(
                    $verdict->reason ?? 'Review rejected the completed work.',
                ),
            );
        }

        $store->mutate(
            ReviewSlice::class,
            static fn (ReviewSlice $slice): ReviewSlice => $slice->record($verdict),
        );

        $store->mutate(
            MessageTimelineSlice::class,
            static fn (MessageTimelineSlice $slice): MessageTimelineSlice => $slice->project($event),
        );
    }

    private function projectStage(CollabEvent $event, CollabStore $store): void
    {
        $store->mutate(
            LoopSlice::class,
            static fn (LoopSlice $slice): LoopSlice => $slice->advance(self::stageFor($event->kind)),
        );
    }

    private function validateReceived(CollabEvent $event): void
    {
        if ($event->envelope === null && $event->workItem === null) {
            return;
        }

        $this->requireEnvelope($event);
        $this->requireWorkItem($event);
    }

    private function validateResult(CollabEvent $event): void
    {
        $workItem = $this->requireWorkItem($event);
        $workResult = $this->requireWorkResult($event);

        if ($workItem->id !== $workResult->itemId) {
            throw new \InvalidArgumentException('Projected work result item id must match the event work item.');
        }

        if ($event->kind === EventKind::WorkItemCompleted && !$workResult->isDone()) {
            throw new \InvalidArgumentException('Completed work projection requires a done result.');
        }

        if ($event->kind === EventKind::WorkInterrupted && $workResult->isDone()) {
            throw new \InvalidArgumentException('Interrupted work projection requires a blocked or failed result.');
        }
    }

    private function validateReview(CollabEvent $event): void
    {
        $this->requireReviewVerdict($event);
    }

    private function requireEnvelope(CollabEvent $event): void
    {
        if ($event->envelope === null) {
            throw new \InvalidArgumentException(sprintf(
                'Collab event "%s" requires an envelope for projection.',
                $event->kind->value,
            ));
        }
    }

    private function requireWorkItem(CollabEvent $event): WorkItem
    {
        return $event->workItem ?? throw new \InvalidArgumentException(sprintf(
            'Collab event "%s" requires a work item for projection.',
            $event->kind->value,
        ));
    }

    private function requireWorkResult(CollabEvent $event): WorkResult
    {
        return $event->workResult ?? throw new \InvalidArgumentException(sprintf(
            'Collab event "%s" requires a work result for projection.',
            $event->kind->value,
        ));
    }

    private function requireReviewVerdict(CollabEvent $event): ReviewVerdict
    {
        return $event->reviewVerdict ?? throw new \InvalidArgumentException(
            'Collab event "work_reviewed" requires a review verdict for projection.',
        );
    }
}
