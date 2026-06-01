<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Core;

use Closure;
use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Concurrency\SettlementBag;
use Phalanx\Runtime\Identity\AegisResourceSid;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Subscription;
use Phalanx\Scope\TaskExecutor;
use Phalanx\Supervisor\TaskHandle;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Theatron\Tui\Drawing\Buffer;
use Phalanx\Theatron\Tui\Drawing\Rect;
use Phalanx\Theatron\Tui\Core\MountedComponent;
use Phalanx\Theatron\Tui\Core\MountSystem;
use Phalanx\Theatron\Tui\Core\SignalScanner;
use Phalanx\Theatron\Tui\Core\RenderContext;
use Phalanx\Theatron\Tui\Core\Component;
use Phalanx\Theatron\Tui\Reactive\DirtyBatch;
use Phalanx\Theatron\Tui\Reactive\Resource;
use Phalanx\Theatron\Tui\Reactive\Signal;
use Phalanx\Theatron\Tui\Drawing\RenderDiagnostics;
use Phalanx\Theatron\Tui\Styles\Theme;
use Phalanx\Theatron\Tui\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tui\Tdom\Painter\Painter;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Worker\WorkerTask;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

use function Phalanx\Theatron\Tui\Kit\column;
use function Phalanx\Theatron\Tui\Kit\mount;

final class MemoryDisposalChurnTest extends PhalanxTestCase
{
    #[Test]
    public function repeatedSlotReplacementDoesNotGrowMountedChildrenOrSubscriptions(): void
    {
        $system = new MountSystem($this->createStub(Scope::class));
        $model = new SlotChurnModel();
        $parent = $this->mountComponent(new SlotChurnParent($model));
        $ctx = $this->renderContext($system);
        $previous = null;

        for ($i = 0; $i < 25; $i++) {
            $model->label->set("label-{$i}");

            $parent->render($ctx);
            $mounted = $system->mounted();
            self::assertCount(1, $mounted);

            $child = $mounted[0];
            $child->render($ctx);

            if ($previous !== null) {
                self::assertTrue($previous->isDisposed);
            }

            self::assertFalse($child->isDisposed);
            self::assertSame(1, $model->childSignal->subscriberCount);

            $previous = $child;
        }

        $parent->dispose();

        self::assertSame(0, $model->label->subscriberCount);
        self::assertSame(0, $model->childSignal->subscriberCount);
        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function repeatedNestedSlotReplacementDisposesDescendants(): void
    {
        $system = new MountSystem($this->createStub(Scope::class));
        $model = new SlotChurnModel();
        $parent = $this->mountComponent(new NestedSlotChurnParent($model));
        $ctx = $this->renderContext($system);
        $previousPair = null;
        $activeSubscriberCount = null;

        for ($i = 0; $i < 20; $i++) {
            $model->label->set("nested-{$i}");

            $parent->render($ctx);
            $nested = $system->mounted()[0];
            $nested->render($ctx);
            $descendant = $system->mounted()[1];
            $descendant->render($ctx);

            if ($previousPair !== null) {
                self::assertTrue($previousPair[0]->isDisposed);
                self::assertTrue($previousPair[1]->isDisposed);
            }

            $activeSubscriberCount ??= $model->childSignal->subscriberCount;

            self::assertCount(2, $system->mounted());
            self::assertSame($activeSubscriberCount, $model->childSignal->subscriberCount);

            $previousPair = [$nested, $descendant];
        }

        $parent->dispose();

        self::assertSame(0, $model->label->subscriberCount);
        self::assertSame(0, $model->childSignal->subscriberCount);
        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function repeatedPaintOwnedSlotReplacementDoesNotGrowMountedChildrenOrSubscriptions(): void
    {
        $system = new MountSystem($this->createStub(Scope::class));
        $model = new SlotChurnModel();
        $renderCtx = $this->renderContext($system);
        $owner = new \stdClass();
        $buffer = Buffer::empty(20, 3);
        $previous = null;

        for ($i = 0; $i < 25; $i++) {
            $model->label->set("paint-{$i}");

            Painter::paint(
                column(
                    mount(
                        SlotChurnChild::class,
                        label: (string) $model->label->get(),
                        signal: $model->childSignal,
                    ),
                ),
                new PaintContext(Rect::sized(20, 3), $buffer, renderContext: $renderCtx, mountOwner: $owner),
            );

            $mounted = $system->mounted();
            self::assertCount(1, $mounted);

            if ($previous !== null) {
                self::assertTrue($previous->isDisposed);
            }

            self::assertFalse($mounted[0]->isDisposed);
            self::assertSame(1, $model->childSignal->subscriberCount);

            $previous = $mounted[0];
        }

        $system->disposeOwnedSlots($owner);

        self::assertSame(0, $model->childSignal->subscriberCount);
        self::assertCount(0, $system->mounted());
    }

    #[Test]
    public function resourceStreamChurnCancelsSupersededTasksAndRejectsDisposedWrites(): void
    {
        $dirty = 0;
        $executor = new QueuedStreamExecutor();

        $resource = new Resource(
            fetcher: static fn(int $key): iterable => ["chunk-{$key}"],
            executor: $executor,
            onDirty: static function () use (&$dirty): void {
                $dirty++;
            },
        );
        $subscription = $resource->subscribe(static function () use (&$dirty): void {
            $dirty++;
        });

        for ($i = 0; $i < 30; $i++) {
            $resource->stream($i);
        }

        self::assertSame(30, $executor->queuedCount());
        self::assertSame(29, $executor->cancelCount());
        self::assertSame(1, $resource->subscriberCount);

        $executor->runLastQueued();

        self::assertTrue($resource->ok);
        self::assertFalse($resource->loading);
        self::assertSame('chunk-29', $resource->buffer);
        self::assertSame('chunk-29', $resource->value);

        $buffer = $resource->buffer;
        $dirtyAfterCompletion = $dirty;
        $value = $resource->value;

        $resource->dispose();
        self::assertSame(0, $resource->subscriberCount);
        self::assertSame(29, $executor->queuedCount());
        self::assertSame($dirtyAfterCompletion, $dirty);

        $resource->stream(999);
        self::assertSame(29, $executor->queuedCount());
        self::assertSame($dirtyAfterCompletion, $dirty);

        $executor->runAllQueued();
        self::assertSame($dirtyAfterCompletion, $dirty);

        $subscription->dispose();

        self::assertTrue($subscription->isDisposed);
        self::assertSame(0, $resource->subscriberCount);
        self::assertSame(29, $executor->cancelCount());
        self::assertSame(0, $executor->queuedCount());
        self::assertSame($buffer, $resource->buffer);
        self::assertSame($value, $resource->value);
        self::assertGreaterThan(0, $dirty);
    }

    #[Test]
    public function diagnosticRenderTaskChurnLeavesNoLiveAegisTaskRuns(): void
    {
        $diagnostics = RenderDiagnostics::enabled();
        $target = new DiagnosticRenderTarget();

        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::TaskRun));

        $this->scope->run(static function (ExecutionScope $scope) use ($diagnostics, $target): void {
            for ($i = 0; $i < 50; $i++) {
                $result = $diagnostics->component(
                    $scope,
                    $target,
                    static fn(): string => 'rendered',
                );

                self::assertSame('rendered', $result);
            }

            for ($i = 0; $i < 50; $i++) {
                $result = $diagnostics->screen(
                    $scope,
                    $target,
                    static fn(): string => 'screened',
                );

                self::assertSame('screened', $result);
            }

            for ($i = 0; $i < 50; $i++) {
                self::assertRuntimeExceptionMessage(
                    'component render failed',
                    static function () use ($diagnostics, $scope, $target): void {
                        $diagnostics->component(
                            $scope,
                            $target,
                            static function (): string {
                                throw new RuntimeException('component render failed');
                            },
                        );
                    },
                );
            }

            for ($i = 0; $i < 50; $i++) {
                self::assertRuntimeExceptionMessage(
                    'screen render failed',
                    static function () use ($diagnostics, $scope, $target): void {
                        $diagnostics->screen(
                            $scope,
                            $target,
                            static function (): string {
                                throw new RuntimeException('screen render failed');
                            },
                        );
                    },
                );
            }
        });

        self::assertSame(0, $this->scope->memory->resources->liveCount(AegisResourceSid::TaskRun));
    }

    private static function assertRuntimeExceptionMessage(string $message, Closure $callback): void
    {
        try {
            $callback();
            self::fail("Expected runtime exception '{$message}'.");
        } catch (RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    private function mountComponent(Component $component): MountedComponent
    {
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);

        return new MountedComponent($component, $batch, $scanResult);
    }

    private function renderContext(MountSystem $system): RenderContext
    {
        return new RenderContext(
            $this->createStub(Scope::class),
            Theme::default(),
            $system,
        );
    }
}

final class SlotChurnModel
{
    public Signal $label;

    public Signal $childSignal;

    public function __construct()
    {
        $this->label = new Signal('initial');
        $this->childSignal = new Signal('child');
    }
}

final class SlotChurnParent implements Component
{
    public function __construct(
        private SlotChurnModel $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return column(
            mount(
                SlotChurnChild::class,
                label: (string) $this->model->label->get(),
                signal: $this->model->childSignal,
            ),
        );
    }
}

final class NestedSlotChurnParent implements Component
{
    public function __construct(
        private SlotChurnModel $model,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return column(
            mount(
                NestedSlotChurnChildOwner::class,
                label: (string) $this->model->label->get(),
                signal: $this->model->childSignal,
            ),
        );
    }
}

final class NestedSlotChurnChildOwner implements Component
{
    public function __construct(
        private(set) string $label,
        private(set) Signal $signal,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return column(
            mount(
                SlotChurnChild::class,
                label: $this->label . '-child',
                signal: $this->signal,
            ),
        );
    }
}

final class SlotChurnChild implements Component
{
    public function __construct(
        private(set) string $label,
        private(set) Signal $signal,
    ) {
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Tui\Kit\text($this->label . ':' . (string) $this->signal->get());
    }
}

final class DiagnosticRenderTarget
{
}

final class QueuedStreamExecutor implements TaskExecutor
{
    /** @var list<array{id:int,task:Closure,cancelled:bool}> */
    private array $tasks = [];

    private int $nextId = 0;

    private int $cancelCount = 0;

    public function queuedCount(): int
    {
        return count($this->tasks);
    }

    public function cancelCount(): int
    {
        return $this->cancelCount;
    }

    public function runLastQueued(): mixed
    {
        if ($this->tasks === []) {
            throw new RuntimeException('No queued tasks remain.');
        }

        return $this->runQueued(array_key_last($this->tasks));
    }

    public function runAllQueued(): void
    {
        while ($this->tasks !== []) {
            $this->runQueued(array_key_first($this->tasks));
        }
    }

    public function go(Closure $fn, ?string $name = null): TaskHandle
    {
        $id = $this->nextId++;
        $this->tasks[] = [
            'id' => $id,
            'task' => $fn,
            'cancelled' => false,
        ];
        $executor = \WeakReference::create($this);

        return new TaskHandle(
            id: "stream-task-{$id}",
            name: $name ?? 'stream-task',
            cancel: static function () use ($executor, $id): void {
                $target = $executor->get();
                if ($target instanceof self) {
                    $target->cancel($id);
                }
            },
            snapshot: static fn(): null => null,
        );
    }

    /** @return array<string|int, mixed> */
    public function concurrent(Scopeable|Executable|Closure ...$tasks): array
    {
        throw new RuntimeException('concurrent is not implemented by the queued stream executor.');
    }

    public function race(Scopeable|Executable|Closure ...$tasks): mixed
    {
        throw new RuntimeException('race is not implemented by the queued stream executor.');
    }

    public function any(Scopeable|Executable|Closure ...$tasks): mixed
    {
        throw new RuntimeException('any is not implemented by the queued stream executor.');
    }

    public function map(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        throw new RuntimeException('map is not implemented by the queued stream executor.');
    }

    /** @return array<string|int, mixed> */
    public function series(Scopeable|Executable|Closure ...$tasks): array
    {
        throw new RuntimeException('series is not implemented by the queued stream executor.');
    }

    public function waterfall(Scopeable|Executable|Closure ...$tasks): mixed
    {
        throw new RuntimeException('waterfall is not implemented by the queued stream executor.');
    }

    public function settle(Scopeable|Executable|Closure ...$tasks): SettlementBag
    {
        throw new RuntimeException('settle is not implemented by the queued stream executor.');
    }

    public function timeout(float $seconds, Scopeable|Executable|Closure $task): mixed
    {
        throw new RuntimeException('timeout is not implemented by the queued stream executor.');
    }

    public function retry(Scopeable|Executable|Closure $task, RetryPolicy $policy): mixed
    {
        throw new RuntimeException('retry is not implemented by the queued stream executor.');
    }

    public function delay(float $seconds): void
    {
        throw new RuntimeException('delay is not implemented by the queued stream executor.');
    }

    public function periodic(float $interval, Closure $tick): Subscription
    {
        throw new RuntimeException('periodic is not implemented by the queued stream executor.');
    }

    public function defer(Scopeable|Executable|Closure $task): void
    {
        throw new RuntimeException('defer is not implemented by the queued stream executor.');
    }

    public function singleflight(string $key, Scopeable|Executable|Closure $task): mixed
    {
        throw new RuntimeException('singleflight is not implemented by the queued stream executor.');
    }

    public function inWorker(WorkerTask $task): mixed
    {
        throw new RuntimeException('inWorker is not implemented by the queued stream executor.');
    }

    /** @return array<string|int, mixed> */
    public function parallel(WorkerTask ...$tasks): array
    {
        throw new RuntimeException('parallel is not implemented by the queued stream executor.');
    }

    public function settleParallel(WorkerTask ...$tasks): SettlementBag
    {
        throw new RuntimeException('settleParallel is not implemented by the queued stream executor.');
    }

    /** @return array<string|int, mixed> */
    public function mapParallel(iterable $items, Closure $fn, int $limit = 10, ?Closure $onEach = null): array
    {
        throw new RuntimeException('mapParallel is not implemented by the queued stream executor.');
    }

    private function runQueued(int $index): mixed
    {
        $entry = $this->tasks[$index] ?? throw new RuntimeException("No queued task at index {$index}.");

        try {
            return $entry['cancelled'] ? null : $entry['task']();
        } finally {
            array_splice($this->tasks, $index, 1);
        }
    }

    private function cancel(int $id): void
    {
        foreach ($this->tasks as $index => $task) {
            if ($task['id'] !== $id || $task['cancelled']) {
                continue;
            }

            $this->tasks[$index]['cancelled'] = true;
            $this->cancelCount++;

            return;
        }
    }
}
