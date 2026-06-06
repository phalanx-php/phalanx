<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Runtime\Events\Event;
use Phalanx\Tui\Runtime\Events\EventKind;
use Phalanx\Tui\Runtime\Lifecycle\LoopStage;
use Phalanx\Tui\Runtime\Messages\Envelope;
use Phalanx\Tui\Runtime\Plans\Activity;
use Phalanx\Tui\Runtime\Plans\WorkItem;
use Phalanx\Tui\Runtime\Plans\WorkItemStatus;
use Phalanx\Tui\Runtime\Plans\WorkPlan;
use Phalanx\Tui\Runtime\Plans\WorkResult;
use Phalanx\Tui\Runtime\Reviews\ReviewVerdict;
use Phalanx\Tui\Runtime\State\Store;
use Phalanx\Tui\Runtime\State\WorkPlanSlice;
use Phalanx\Tui\Runtime\WorkContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkContextTest extends TestCase
{
    #[Test]
    public function advanceProjectsTheLoopStageWithoutQueueingDomainEvents(): void
    {
        $store = new Store();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);

        $slice = $ctx->advance(LoopStage::Execute);

        self::assertSame(LoopStage::Execute, $slice->stage);
        self::assertSame(LoopStage::Execute, $ctx->stage);
        self::assertSame(LoopStage::Execute, $store->loop->stage);
        self::assertSame([], $ctx->drainProjectedEvents());
    }

    #[Test]
    public function recordProjectsAnEnvelopeToTheMessageTimeline(): void
    {
        $store = new Store();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);
        $envelope = Envelope::prompt('Review the patch');

        $slice = $ctx->record($envelope);

        self::assertSame([$envelope], $slice->envelopes);
        self::assertSame([$envelope], $store->messages->envelopes);
        self::assertSame(EventKind::WorkReceived, $ctx->drainProjectedEvents()[0]->kind);
    }

    #[Test]
    public function fulfillProjectsRunningWorkAndResultEnvelopes(): void
    {
        $store = new Store();
        $plan = WorkPlan::start(new WorkItem(Activity::Testing, 'Run focused tests', id: 'tc-4a'));
        $plan->startItem('tc-4a');
        $store->workPlan = new WorkPlanSlice($plan);

        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);
        $envelope = Envelope::prompt('Tests passed');
        $result = WorkResult::done('tc-4a', envelopes: [$envelope]);

        $slice = $ctx->fulfill('tc-4a', $result);

        self::assertSame(WorkItemStatus::Done, $slice->plan->item('tc-4a')->status);
        self::assertSame(WorkItemStatus::Done, $ctx->plan->item('tc-4a')->status);
        self::assertSame([$envelope], $store->messages->envelopes);
        self::assertSame(EventKind::WorkItemCompleted, $ctx->drainProjectedEvents()[0]->kind);
    }

    #[Test]
    public function appendAndStartProjectWorkPlanChanges(): void
    {
        $store = new Store();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);

        $ctx->append(new WorkItem(Activity::Testing, 'Run focused tests', id: 'tc-5a'));
        $slice = $ctx->start('tc-5a');

        self::assertSame(WorkItemStatus::Running, $slice->plan->item('tc-5a')->status);
        self::assertSame(WorkItemStatus::Running, $store->workPlan->plan->item('tc-5a')->status);
        self::assertSame(
            [EventKind::WorkPrepared, EventKind::WorkItemStarted],
            array_map(
                static fn (Event $event): EventKind => $event->kind,
                $ctx->drainProjectedEvents(),
            ),
        );
    }

    #[Test]
    public function reviewProjectsVerdictsThroughTheStore(): void
    {
        $store = new Store();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);
        $verdict = ReviewVerdict::approve();

        $slice = $ctx->review($verdict);

        self::assertSame([$verdict], $slice->verdicts);
        self::assertSame([$verdict], $store->reviews->verdicts);
        self::assertSame(EventKind::WorkReviewed, $ctx->drainProjectedEvents()[0]->kind);
    }

    #[Test]
    public function abortProjectsARejectedReviewVerdict(): void
    {
        $store = new Store();
        $plan = WorkPlan::start(new WorkItem(Activity::Testing, 'Run focused tests', id: 'tc-5a'));
        $store->workPlan = new WorkPlanSlice($plan);
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);

        $slice = $ctx->abort('review rejected the result');

        self::assertSame('review rejected the result', $slice->plan->statusReason);
        self::assertSame('review rejected the result', $store->workPlan->plan->statusReason);
        self::assertSame(EventKind::WorkReviewed, $ctx->drainProjectedEvents()[0]->kind);
    }

    #[Test]
    public function failedProjectionLeavesStoreUntouched(): void
    {
        $store = new Store();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);

        $ctx->append(new WorkItem(Activity::Testing, 'Run focused tests', id: 'tc-5a'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists in this plan');

        try {
            $ctx->append(new WorkItem(Activity::Testing, 'Duplicate tests', id: 'tc-5a'));
        } finally {
            self::assertCount(1, $store->workPlan->plan->items());
            self::assertSame('Run focused tests', $store->workPlan->plan->item('tc-5a')->workItem->prompt);
        }
    }

    #[Test]
    public function visiblePlanCannotMutateStoreStateDirectly(): void
    {
        $store = new Store();
        $plan = WorkPlan::start(new WorkItem(Activity::Testing, 'Run focused tests', id: 'tc-4a'));
        $store->workPlan = new WorkPlanSlice($plan);

        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);
        $visible = $ctx->plan;
        $visible->append(new WorkItem(Activity::Reviewing, 'Review follow-up', id: 'follow-up'));

        self::assertCount(1, $ctx->plan->items());
        self::assertSame('tc-4a', $ctx->plan->items()[0]->workItem->id);
    }

    #[Test]
    public function fulfillRejectsMismatchedResultIds(): void
    {
        $ctx = new WorkContext($this->createStub(TaskScope::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must match');

        $ctx->fulfill('expected', WorkResult::done('actual'));
    }
}
