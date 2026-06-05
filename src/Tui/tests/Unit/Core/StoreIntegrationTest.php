<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Core;

use Phalanx\Tui\Core\MountedComponent;
use Phalanx\Tui\Core\MountSystem;
use Phalanx\Tui\Core\SignalScanner;
use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Core\Component;
use Phalanx\Tui\Reactive\DirtyBatch;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Reactive\Tracker;
use Phalanx\Tui\Reactive\Store;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Renderable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoreIntegrationTest extends TestCase
{
    #[Test]
    public function scannerFindsStoreProperties(): void
    {
        $store = new SpartanStore();
        $component = new SpartanComponent($store);
        $batch = new DirtyBatch();

        $result = SignalScanner::scan($component, $batch);

        self::assertCount(1, $result->storeSubscriptions);
    }

    #[Test]
    public function storeMutationMarksDirtyBatch(): void
    {
        $store = new SpartanStore();
        $component = new SpartanComponent($store);
        $batch = new DirtyBatch();

        SignalScanner::scan($component, $batch);

        self::assertFalse($batch->isDirty);

        $store->warriors = new WarriorSlice(300);

        self::assertTrue($batch->isDirty);
    }

    #[Test]
    public function storeSubscriptionDisposedOnComponentDispose(): void
    {
        $store = new SpartanStore();
        $component = new SpartanComponent($store);
        $batch = new DirtyBatch();

        $result = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $result);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);
        $mounted->render($ctx);

        $mounted->dispose();
        $batch->consume();

        $store->warriors = new WarriorSlice(150);

        self::assertFalse($batch->isDirty, 'Store changes must not reach disposed component');
    }

    #[Test]
    public function mixedSignalAndStorePropertiesWork(): void
    {
        $store = new SpartanStore();
        $component = new MixedComponent($store);
        $batch = new DirtyBatch();

        $result = SignalScanner::scan($component, $batch);

        self::assertCount(1, $result->storeSubscriptions);
        self::assertCount(1, $result->subscriptions);
        self::assertCount(1, $result->ownedSignals);

        $store->warriors = new WarriorSlice(100);
        self::assertTrue($batch->isDirty);

        $batch->consume();

        $component->morale->set('high');
        self::assertTrue($batch->isDirty);
    }

    #[Test]
    public function storeNotDisposedOnComponentDispose(): void
    {
        $store = new SpartanStore();
        $component = new SpartanComponent($store);
        $batch = new DirtyBatch();

        $result = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $result);

        $scope = $this->createStub(\Phalanx\Scope\Scope::class);
        $mountSystem = new MountSystem($scope);
        $ctx = new RenderContext($scope, Theme::default(), $mountSystem);
        $mounted->render($ctx);
        $mounted->dispose();

        $store->warriors = new WarriorSlice(500);
        self::assertSame(500, $store->warriors->count);
    }

    #[Test]
    public function dirtyBatchDeduplicatesMultipleStoreWrites(): void
    {
        $store = new SpartanStore();
        $component = new SpartanComponent($store);
        $batch = new DirtyBatch();

        SignalScanner::scan($component, $batch);

        $store->warriors = new WarriorSlice(100);
        $store->warriors = new WarriorSlice(200);
        $store->warriors = new WarriorSlice(300);

        self::assertTrue($batch->isDirty);
        self::assertSame(1, $batch->requests);
    }

    protected function tearDown(): void
    {
        while (Tracker::isTracking()) {
            Tracker::pop(0);
        }
    }
}

final class WarriorSlice
{
    public function __construct(
        private(set) int $count = 0,
    ) {
    }

    public function reinforce(int $amount): self
    {
        return new self($this->count + $amount);
    }
}

final class SpartanStore extends Store
{
    public WarriorSlice $warriors {
        get => $this->read(WarriorSlice::class);
        set {
            $this->write(WarriorSlice::class, $value);
        }
    }

    public function __construct()
    {
        $this->register(WarriorSlice::class, new WarriorSlice());
    }
}

final class SpartanComponent implements Component
{
    public function __construct(
        private(set) SpartanStore $store,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Kit\text('Warriors: ' . $this->store->warriors->count);
    }
}

final class MixedComponent implements Component
{
    public function __construct(
        private(set) SpartanStore $store,
        private(set) Signal $morale = new Signal('steady'),
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Kit\text($this->store->warriors->count . ' - ' . $this->morale->get());
    }
}
