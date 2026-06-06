<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Runtime\State;

use Phalanx\Tui\Runtime\Lifecycle\LoopStage;
use Phalanx\Tui\Runtime\Messages\Envelope;
use Phalanx\Tui\Runtime\Plans\Activity;
use Phalanx\Tui\Runtime\Plans\WorkItem;
use Phalanx\Tui\Runtime\Plans\WorkPlan;
use Phalanx\Tui\Runtime\State\Store;
use Phalanx\Tui\Runtime\State\ContextSlice;
use Phalanx\Tui\Runtime\State\DevToolsSlice;
use Phalanx\Tui\Runtime\State\EffectSlice;
use Phalanx\Tui\Runtime\State\InputComposerSlice;
use Phalanx\Tui\Runtime\State\LoopSlice;
use Phalanx\Tui\Runtime\State\MessageTimelineSlice;
use Phalanx\Tui\Runtime\State\NotificationSlice;
use Phalanx\Tui\Runtime\State\ParticipantSlice;
use Phalanx\Tui\Runtime\State\ReviewSlice;
use Phalanx\Tui\Runtime\State\RuntimeSlice;
use Phalanx\Tui\Runtime\State\WorkPlanSlice;
use Phalanx\Tui\Runtime\State\WorkspaceViewSlice;
use Phalanx\Tui\Core\Component;
use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Core\SignalScanner;
use Phalanx\Tui\Reactive\DirtyBatch;
use Phalanx\Tui\Reactive\Tracker;
use Phalanx\Tui\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    #[Test]
    public function registersEveryRuntimeSlice(): void
    {
        $store = new Store();

        self::assertInstanceOf(MessageTimelineSlice::class, $store->messages);
        self::assertInstanceOf(WorkPlanSlice::class, $store->workPlan);
        self::assertInstanceOf(LoopSlice::class, $store->loop);
        self::assertInstanceOf(EffectSlice::class, $store->effects);
        self::assertInstanceOf(ReviewSlice::class, $store->reviews);
        self::assertInstanceOf(ParticipantSlice::class, $store->participants);
        self::assertInstanceOf(ContextSlice::class, $store->context);
        self::assertInstanceOf(RuntimeSlice::class, $store->runtime);
        self::assertInstanceOf(InputComposerSlice::class, $store->inputComposer);
        self::assertInstanceOf(WorkspaceViewSlice::class, $store->workspaceView);
        self::assertInstanceOf(NotificationSlice::class, $store->notifications);
        self::assertInstanceOf(DevToolsSlice::class, $store->devTools);
    }

    #[Test]
    public function propertyWritesNotifySubscribers(): void
    {
        $store = new Store();
        $calls = 0;

        $store->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $store->loop = new LoopSlice(LoopStage::Review);

        self::assertSame(1, $calls);
        self::assertSame(LoopStage::Review, $store->loop->stage);
    }

    #[Test]
    public function messageTimelineIsCopyOnWrite(): void
    {
        $original = new MessageTimelineSlice();
        $envelope = Envelope::prompt('Draft the plan');

        $next = $original->record($envelope);

        self::assertSame([], $original->envelopes);
        self::assertSame([$envelope], $next->envelopes);
    }

    #[Test]
    public function workPlanSliceIsolatesMutablePlanInstances(): void
    {
        $plan = WorkPlan::start(new WorkItem(Activity::Testing, 'Run focused tests', id: 'tc-4a'));
        $slice = new WorkPlanSlice($plan);

        $plan->append(new WorkItem(Activity::Reviewing, 'Review follow-up', id: 'follow-up'));
        $visible = $slice->plan;
        $visible->append(new WorkItem(Activity::Exploring, 'Explore follow-up', id: 'explore'));

        self::assertCount(1, $slice->plan->items());
        self::assertSame('tc-4a', $slice->plan->items()[0]->workItem->id);
    }

    #[Test]
    public function tuiSignalScannerCanSubscribeToRuntimeStoreReads(): void
    {
        $store = new Store();
        $component = new class ($store) implements Component {
            public function __construct(
                private(set) Store $store,
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text($this->store->loop->stage->value);
            }
        };

        $batch = new DirtyBatch();

        $result = SignalScanner::scan($component, $batch);
        self::assertCount(1, $result->storeSubscriptions);

        $store->loop = new LoopSlice(LoopStage::Complete);

        self::assertTrue($batch->isDirty);
    }

    protected function tearDown(): void
    {
        while (Tracker::isTracking()) {
            Tracker::pop(0);
        }
    }
}
