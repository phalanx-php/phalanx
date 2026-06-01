<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkItemStatus;
use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\Plans\WorkResult;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;
use Phalanx\Theatron\Collab\WorkContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkContextTest extends TestCase
{
    #[Test]
    public function advanceMovesTheLoopStageThroughTheStore(): void
    {
        $store = new CollabStore();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);

        $slice = $ctx->advance(LoopStage::Collaborate);

        self::assertSame(LoopStage::Collaborate, $slice->stage);
        self::assertSame(LoopStage::Collaborate, $ctx->stage);
        self::assertSame(LoopStage::Collaborate, $store->loop->stage);
    }

    #[Test]
    public function recordAppendsAnEnvelopeToTheMessageTimeline(): void
    {
        $store = new CollabStore();
        $ctx = new WorkContext($this->createStub(TaskScope::class), $store);
        $envelope = Envelope::prompt('Review the patch');

        $slice = $ctx->record($envelope);

        self::assertSame([$envelope], $slice->envelopes);
        self::assertSame([$envelope], $store->messages->envelopes);
    }

    #[Test]
    public function fulfillCompletesRunningWorkAndRecordsResultEnvelopes(): void
    {
        $store = new CollabStore();
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
    }

    #[Test]
    public function visiblePlanCannotMutateStoreStateDirectly(): void
    {
        $store = new CollabStore();
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
