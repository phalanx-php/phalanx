<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Collab\State;

use Phalanx\Theatron\Collab\Lifecycle\LoopStage;
use Phalanx\Theatron\Collab\Messages\Envelope;
use Phalanx\Theatron\Collab\Plans\Activity;
use Phalanx\Theatron\Collab\Plans\WorkItem;
use Phalanx\Theatron\Collab\Plans\WorkPlan;
use Phalanx\Theatron\Collab\State\CollabStore;
use Phalanx\Theatron\Collab\State\ContextSlice;
use Phalanx\Theatron\Collab\State\DevToolsSlice;
use Phalanx\Theatron\Collab\State\EffectSlice;
use Phalanx\Theatron\Collab\State\InputComposerSlice;
use Phalanx\Theatron\Collab\State\LoopSlice;
use Phalanx\Theatron\Collab\State\MessageTimelineSlice;
use Phalanx\Theatron\Collab\State\NotificationSlice;
use Phalanx\Theatron\Collab\State\ParticipantSlice;
use Phalanx\Theatron\Collab\State\ReviewSlice;
use Phalanx\Theatron\Collab\State\RuntimeSlice;
use Phalanx\Theatron\Collab\State\WorkPlanSlice;
use Phalanx\Theatron\Collab\State\WorkspaceViewSlice;
use Phalanx\Theatron\Tui\Core\Component;
use Phalanx\Theatron\Tui\Core\RenderContext;
use Phalanx\Theatron\Tui\Core\SignalScanner;
use Phalanx\Theatron\Tui\Reactive\DirtyBatch;
use Phalanx\Theatron\Tui\Reactive\Tracker;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollabStoreTest extends TestCase
{
    #[Test]
    public function registersEveryCollabSlice(): void
    {
        $store = new CollabStore();

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
        $store = new CollabStore();
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
    public function tuiSignalScannerCanSubscribeToCollabStoreReads(): void
    {
        $store = new CollabStore();
        $component = new class($store) implements Component {
            public function __construct(
                private(set) CollabStore $store,
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Theatron\Tui\Kit\text($this->store->loop->stage->value);
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
