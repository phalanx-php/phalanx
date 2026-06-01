<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\Projection;

use DateTimeImmutable;
use Phalanx\Theatron\Collab\Events\CollabEvent;
use Phalanx\Theatron\Collab\Events\EventKind;
use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Messages\Address;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Messages\MessageKind;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkItemStatus;
use Phalanx\Theatron\Collab\Plans\WorkPlanStatus;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\Projection\CollabProjector;
use Phalanx\Theatron\Collab\Reviews\ReviewVerdict;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\TimelineEntryKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollabProjectorTest extends TestCase
{
    #[Test]
    public function projectsReceivedPromptIntoTimelineAndWorkPlan(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $envelope = Envelope::make(
            from: Address::user('admin'),
            to: Address::agent('primary'),
            kind: MessageKind::Prompt,
            payload: 'Draft TC-8A',
            id: 'env_prompt',
        );
        $workItem = new WorkItem(Activity::Thinking, 'Draft TC-8A', id: 'work_prompt');

        $projector->project(CollabEvent::record(
            EventKind::WorkReceived,
            envelope: $envelope,
            workItem: $workItem,
            occurredAt: self::time(),
            id: 'evt_received',
        ), $store);

        self::assertSame(LoopStage::Receive, $store->loop->stage);
        self::assertSame([$envelope], $store->messages->envelopes);
        self::assertSame(TimelineEntryKind::Prompt, $store->messages->entries[0]->kind);
        self::assertSame('evt_received', $store->messages->entries[0]->eventId);
        self::assertSame('env_prompt', $store->messages->entries[0]->envelopeId);
        self::assertSame('work_prompt', $store->workPlan->plan->item('work_prompt')->workItem->id);
    }

    #[Test]
    public function projectsLoopStageEventsIntoLoopSlice(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();

        $projector->project(CollabEvent::record(EventKind::WorkPrepared, id: 'evt_prepared'), $store);
        self::assertSame(LoopStage::Prepare, $store->loop->stage);

        $projector->project(CollabEvent::record(EventKind::WorkDistributed, id: 'evt_distributed'), $store);
        self::assertSame(LoopStage::Distribute, $store->loop->stage);

        $projector->project(CollabEvent::record(EventKind::WorkCompleted, id: 'evt_completed'), $store);
        self::assertSame(LoopStage::Complete, $store->loop->stage);
    }

    #[Test]
    public function projectsStartedAndCompletedWorkIntoPlan(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Testing, 'Run checks', id: 'work_checks');
        $response = Envelope::make(
            from: Address::agent('primary'),
            to: Address::user(),
            kind: MessageKind::Response,
            payload: 'Checks passed.',
            id: 'env_response',
        );

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_started',
        ), $store);

        self::assertSame(WorkItemStatus::Running, $store->workPlan->plan->item('work_checks')->status);

        $projector->project(CollabEvent::record(
            EventKind::WorkItemCompleted,
            workItem: $workItem,
            workResult: WorkResult::done('work_checks', summary: 'Checks passed.', envelopes: [$response]),
            id: 'evt_done',
        ), $store);

        $item = $store->workPlan->plan->item('work_checks');

        self::assertSame(WorkItemStatus::Done, $item->status);
        self::assertSame(WorkPlanStatus::Complete, $store->workPlan->plan->status);
        self::assertSame(['env_prompt', 'env_response'], array_map(
            static fn (Envelope $envelope): string => $envelope->id,
            $store->messages->envelopes,
        ));
        self::assertSame(TimelineEntryKind::Response, $store->messages->entries[2]->kind);
        self::assertSame(TimelineEntryKind::WorkCompleted, $store->messages->entries[3]->kind);
    }

    #[Test]
    public function projectsInterruptedWorkIntoSuspendedPlan(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Researching, 'Wait for token', id: 'work_wait');

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_wait_started',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkInterrupted,
            workItem: $workItem,
            workResult: WorkResult::blocked('work_wait', 'missing token'),
            id: 'evt_wait_blocked',
        ), $store);

        $item = $store->workPlan->plan->item('work_wait');

        self::assertSame(WorkItemStatus::Blocked, $item->status);
        self::assertSame(WorkPlanStatus::Suspended, $store->workPlan->plan->status);
        self::assertSame('missing token', $item->blockedReason);
        self::assertSame(TimelineEntryKind::WorkInterrupted, $store->messages->entries[2]->kind);
    }

    #[Test]
    public function projectsReviewVerdictsAndRevisionWork(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch');

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_patch_started',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemCompleted,
            workItem: $workItem,
            workResult: WorkResult::done('work_patch', summary: 'Patch done.'),
            id: 'evt_patch_done',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkReviewed,
            reviewVerdict: ReviewVerdict::revise('Need tests.', [
                new WorkItem(Activity::Testing, 'Add focused tests', id: 'work_tests'),
            ]),
            id: 'evt_review',
        ), $store);

        self::assertSame('Need tests.', $store->reviews->verdicts[0]->reason);
        self::assertSame('work_tests', $store->workPlan->plan->item('work_tests')->workItem->id);
        self::assertSame(TimelineEntryKind::Review, $store->messages->entries[3]->kind);
    }

    #[Test]
    public function rejectsIncompleteProjectionPayloads(): void
    {
        $projector = new CollabProjector();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a work item');

        $projector->project(CollabEvent::record(
            EventKind::WorkReceived,
            envelope: Envelope::make(
                from: Address::user(),
                to: Address::agent('primary'),
                kind: MessageKind::Prompt,
                payload: 'Draft TC-8A',
            ),
        ), new CollabStore());
    }

    #[Test]
    public function failedProjectionDoesNotMutateTheStore(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a work item');

        try {
            $projector->project(CollabEvent::record(EventKind::WorkItemStarted), $store);
        } finally {
            self::assertSame(LoopStage::Receive, $store->loop->stage);
            self::assertSame([], $store->messages->entries);
            self::assertSame([], $store->workPlan->plan->items());
        }
    }

    #[Test]
    public function unsupportedProjectionDoesNotMutateTheStore(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not supported by the alpha projector');

        try {
            $projector->project(CollabEvent::record(EventKind::EffectRequested), $store);
        } finally {
            self::assertSame(LoopStage::Receive, $store->loop->stage);
            self::assertSame([], $store->messages->entries);
        }
    }

    #[Test]
    public function failedRevisionProjectionDoesNotRecordPartialReviewState(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch');

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_patch_started',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemCompleted,
            workItem: $workItem,
            workResult: WorkResult::done('work_patch'),
            id: 'evt_patch_done',
        ), $store);

        $entryCount = count($store->messages->entries);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists in this plan');

        try {
            $projector->project(CollabEvent::record(
                EventKind::WorkReviewed,
                reviewVerdict: ReviewVerdict::revise('Need tests.', [
                    new WorkItem(Activity::Testing, 'Add focused tests', id: 'work_patch'),
                ]),
                id: 'evt_bad_review',
            ), $store);
        } finally {
            self::assertSame(LoopStage::Collaborate, $store->loop->stage);
            self::assertSame([], $store->reviews->verdicts);
            self::assertCount($entryCount, $store->messages->entries);
        }
    }

    #[Test]
    public function rejectedReviewProjectionAbortsThePlan(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Editing, 'Patch code', id: 'work_patch');

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_patch_started',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemCompleted,
            workItem: $workItem,
            workResult: WorkResult::done('work_patch', summary: 'Patch done.'),
            id: 'evt_patch_done',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkReviewed,
            reviewVerdict: ReviewVerdict::reject('Unsafe change.'),
            id: 'evt_rejected',
        ), $store);

        self::assertSame(WorkPlanStatus::Aborted, $store->workPlan->plan->status);
        self::assertSame('Unsafe change.', $store->workPlan->plan->statusReason);
        self::assertSame(TimelineEntryKind::Review, $store->messages->entries[3]->kind);
        self::assertSame('Unsafe change.', $store->reviews->verdicts[0]->reason);
    }

    #[Test]
    public function completedProjectionUsesFallbackTimelineSummaryForBlankResultSummary(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Testing, 'Run checks', id: 'work_checks');

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_started',
        ), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemCompleted,
            workItem: $workItem,
            workResult: WorkResult::done('work_checks', summary: ''),
            id: 'evt_done',
        ), $store);

        self::assertSame('Completed work_checks.', $store->messages->entries[2]->summary);
    }

    #[Test]
    public function completedProjectionRequiresDoneResultAndDoesNotMutateStore(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Testing, 'Run checks', id: 'work_checks');

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_started',
        ), $store);

        $entryCount = count($store->messages->entries);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a done result');

        try {
            $projector->project(CollabEvent::record(
                EventKind::WorkItemCompleted,
                workItem: $workItem,
                workResult: WorkResult::blocked('work_checks', 'waiting on user'),
                id: 'evt_bad_completed',
            ), $store);
        } finally {
            self::assertSame(LoopStage::Collaborate, $store->loop->stage);
            self::assertSame(WorkItemStatus::Running, $store->workPlan->plan->item('work_checks')->status);
            self::assertCount($entryCount, $store->messages->entries);
        }
    }

    #[Test]
    public function interruptedProjectionRejectsDoneResultAndDoesNotMutateStore(): void
    {
        $store = new CollabStore();
        $projector = new CollabProjector();
        $workItem = new WorkItem(Activity::Researching, 'Wait for token', id: 'work_wait');

        $projector->project(self::received($workItem), $store);
        $projector->project(CollabEvent::record(
            EventKind::WorkItemStarted,
            workItem: $workItem,
            id: 'evt_wait_started',
        ), $store);

        $entryCount = count($store->messages->entries);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a blocked or failed result');

        try {
            $projector->project(CollabEvent::record(
                EventKind::WorkInterrupted,
                workItem: $workItem,
                workResult: WorkResult::done('work_wait'),
                id: 'evt_bad_interrupted',
            ), $store);
        } finally {
            self::assertSame(LoopStage::Collaborate, $store->loop->stage);
            self::assertSame(WorkItemStatus::Running, $store->workPlan->plan->item('work_wait')->status);
            self::assertCount($entryCount, $store->messages->entries);
        }
    }

    private static function received(WorkItem $workItem): CollabEvent
    {
        return CollabEvent::record(
            EventKind::WorkReceived,
            envelope: Envelope::make(
                from: Address::user(),
                to: Address::agent('primary'),
                kind: MessageKind::Prompt,
                payload: $workItem->prompt,
                id: 'env_prompt',
            ),
            workItem: $workItem,
            occurredAt: self::time(),
            id: 'evt_received',
        );
    }

    private static function time(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-05-31T00:00:00+00:00');
    }
}
