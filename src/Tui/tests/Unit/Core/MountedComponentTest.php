<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Core;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Core\MountedComponent;
use Phalanx\Tui\Core\MountSystem;
use Phalanx\Tui\Core\SignalScanner;
use Phalanx\Tui\Core\RenderContext;
use Phalanx\Tui\Core\Component;
use Phalanx\Tui\Core\Disposable as TuiDisposable;
use Phalanx\Tui\Core\Mountable;
use Phalanx\Tui\Reactive\Computed;
use Phalanx\Tui\Reactive\DirtyBatch;
use Phalanx\Tui\Reactive\Resource;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Reactive\Tracker;
use Phalanx\Tui\Drawing\RenderDiagnostics;
use Phalanx\Tui\Styles\Theme;
use Phalanx\Tui\Tdom\Element\TextElement;
use Phalanx\Tui\Tdom\Renderable;
use Phalanx\Tui\Tests\Support\ClockProbe;
use Phalanx\Tui\Tests\Support\RecordingTaskScope;
use Phalanx\Trace\TraceType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function Phalanx\Tui\Kit\mount;

final class MountedComponentTest extends TestCase
{
    #[Test]
    public function implementsRenderable(): void
    {
        $mounted = $this->createMounted(new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('hello');
            }
        });

        self::assertInstanceOf(Renderable::class, $mounted);
    }

    #[Test]
    public function styleReturnsNull(): void
    {
        $mounted = $this->createMounted(new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }
        });

        self::assertNull($mounted->style);
    }

    #[Test]
    public function startsAsDirty(): void
    {
        $mounted = $this->createMounted(new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }
        });

        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function renderClearsDirtyFlag(): void
    {
        $mounted = $this->createMounted(new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }
        });

        $mounted->render($this->createRenderCtx());

        self::assertFalse($mounted->isDirty);
    }

    #[Test]
    public function renderStoresLastResult(): void
    {
        $mounted = $this->createMounted(new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('rendered');
            }
        });

        self::assertNull($mounted->lastResult());

        $result = $mounted->render($this->createRenderCtx());

        self::assertSame($result, $mounted->lastResult());
        self::assertInstanceOf(TextElement::class, $result);
    }

    #[Test]
    public function renderRunsInsideTrackerFrame(): void
    {
        $signal = new Signal('hello');
        $component = new class ($signal) implements Component {
            public function __construct(
                private(set) Signal $data,
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $val = $this->data->get();

                return \Phalanx\Tui\Kit\text((string) $val);
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch, ['data' => $signal]);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $frame = Tracker::push();
        $mounted->render($this->createRenderCtx());
        $deps = Tracker::pop($frame);

        self::assertEmpty($deps, 'Component render uses its own Tracker frame, not the parent');
    }

    #[Test]
    public function signalWriteMarksDirty(): void
    {
        $component = new class () implements Component {
            public function __construct(
                private(set) Signal $count = new Signal(0),
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text((string) $this->count->get());
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $mounted->render($this->createRenderCtx());
        self::assertFalse($mounted->isDirty);
        self::assertSame(1, $component->count->subscriberCount);
        self::assertSame(1, $mounted->subscriptionCount);

        $component->count->set(null, 42);

        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function renderTimeConditionalDependenciesAreReconciled(): void
    {
        $model = new \stdClass();
        $model->useA = new Signal(true);
        $model->a = new Signal('a');
        $model->b = new Signal('b');

        $component = new class ($model) implements Component {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $value = $this->model->useA->get()
                    ? $this->model->a->get()
                    : $this->model->b->get();

                return \Phalanx\Tui\Kit\text((string) $value);
            }
        };

        $mounted = $this->createMounted($component);
        $ctx = $this->createRenderCtx();

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);
        self::assertSame(2, $mounted->subscriptionCount);

        $model->useA->set(null, false);
        self::assertTrue($mounted->isDirty);

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);
        self::assertSame(2, $mounted->subscriptionCount);

        $model->a->set(null, 'old branch');
        self::assertFalse($mounted->isDirty, 'Old render dependency must be unsubscribed');

        $model->b->set(null, 'new branch');
        self::assertTrue($mounted->isDirty, 'Current render dependency must stay subscribed');
    }

    #[Test]
    public function renderTimeResourceDependencyMarksDirty(): void
    {
        $model = new \stdClass();
        $model->reply = new Resource(static fn(): iterable => ['streamed']);

        $component = new class ($model) implements Component {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text($this->model->reply->buffer);
            }
        };

        $mounted = $this->createMounted($component);
        $mounted->render($this->createRenderCtx());
        self::assertFalse($mounted->isDirty);

        $model->reply->stream();

        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function typedResourceReadDuringRenderDoesNotDuplicateScannerSubscription(): void
    {
        $component = new class (new Resource(static fn(): iterable => ['streamed'])) implements Component {
            public function __construct(
                private(set) Resource $reply,
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text($this->reply->buffer);
            }
        };

        $mounted = $this->createMounted($component);
        self::assertSame(1, $component->reply->subscriberCount);

        $mounted->render($this->createRenderCtx());

        self::assertSame(1, $component->reply->subscriberCount);
        self::assertSame(1, $mounted->subscriptionCount);
    }

    #[Test]
    public function resourceStatusReadsDuringRenderMarkDirty(): void
    {
        $calls = 0;
        $model = new \stdClass();
        $model->reply = new Resource(
            fetcher: static function () use (&$calls): string {
                $calls++;

                if ($calls > 1) {
                    throw new \RuntimeException('failed');
                }

                return 'ready';
            },
        );

        $component = new class ($model) implements Component {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $error = $this->model->reply->error?->getMessage() ?? '';

                return \Phalanx\Tui\Kit\text(sprintf(
                    '%s:%s:%s:%s',
                    $this->model->reply->loading ? 'loading' : 'idle',
                    $this->model->reply->ok ? 'ok' : 'pending',
                    (string) $this->model->reply->value,
                    $error,
                ));
            }
        };

        $mounted = $this->createMounted($component);
        $ctx = $this->createRenderCtx();
        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->reply->refresh();
        self::assertTrue($mounted->isDirty);

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->reply->refresh();
        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function renderTimeComputedDependencyMarksDirty(): void
    {
        $model = new \stdClass();
        $model->source = new Signal('first');
        $model->label = new Computed(static fn(): string => strtoupper((string) $model->source->get()));

        $component = new class ($model) implements Component {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text((string) $this->model->label->value);
            }
        };

        $mounted = $this->createMounted($component);
        $mounted->render($this->createRenderCtx());
        self::assertFalse($mounted->isDirty);

        $model->source->set(null, 'second');

        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function disposeCleansRenderTimeDependencies(): void
    {
        $model = new \stdClass();
        $model->signal = new Signal('active');

        $component = new class ($model) implements Component {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text((string) $this->model->signal->get());
            }
        };

        $mounted = $this->createMounted($component);
        $mounted->render($this->createRenderCtx());
        self::assertSame(1, $mounted->subscriptionCount);

        $mounted->dispose();
        $model->signal->set(null, 'after dispose');

        self::assertFalse($mounted->isDirty);
        self::assertSame(0, $mounted->subscriptionCount);
    }

    #[Test]
    public function failedRenderPreservesPreviousRenderDependencies(): void
    {
        $model = new \stdClass();
        $model->throw = false;
        $model->good = new Signal('good');
        $model->bad = new Signal('bad');

        $component = new class ($model) implements Component {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                if ($this->model->throw) {
                    $this->model->bad->get();

                    throw new \RuntimeException('render failed');
                }

                return \Phalanx\Tui\Kit\text((string) $this->model->good->get());
            }
        };

        $mounted = $this->createMounted($component);
        $ctx = $this->createRenderCtx();
        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->throw = true;

        try {
            $mounted->render($ctx);
            self::fail('Expected failed render.');
        } catch (\RuntimeException $e) {
            self::assertSame('render failed', $e->getMessage());
        }

        self::assertTrue($mounted->isDirty, 'Failed render must leave mounted component dirty');
        $model->throw = false;

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->throw = true;

        try {
            $mounted->render($ctx);
            self::fail('Expected failed render.');
        } catch (\RuntimeException $e) {
            self::assertSame('render failed', $e->getMessage());
        }

        $model->throw = false;

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->bad->set(null, 'bad changed');
        self::assertFalse($mounted->isDirty, 'Failed render deps must not replace previous deps');

        $model->good->set(null, 'good changed');
        self::assertTrue($mounted->isDirty, 'Previous successful render deps must remain active');
    }

    #[Test]
    public function failedReconcileLeavesPreviousRenderDependenciesSubscribed(): void
    {
        $model = new \stdClass();
        $model->useBad = new Signal(false);
        $model->good = new Signal('good');
        $model->bad = new Signal('bad');
        $model->bad->dispose();

        $component = new class ($model) implements Component {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $value = $this->model->useBad->get()
                    ? $this->model->bad->get()
                    : $this->model->good->get();

                return \Phalanx\Tui\Kit\text((string) $value);
            }
        };

        $mounted = $this->createMounted($component);
        $ctx = $this->createRenderCtx();
        $mounted->render($ctx);
        self::assertSame(1, $model->good->subscriberCount);

        $model->useBad->set(null, true);

        try {
            $mounted->render($ctx);
            self::fail('Expected disposed render dependency subscription failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('Cannot subscribe to a disposed signal.', $e->getMessage());
        }

        self::assertTrue($mounted->isDirty);
        self::assertSame(1, $model->good->subscriberCount);
    }

    #[Test]
    public function rerenderUsesStoredContext(): void
    {
        $tracker = new \stdClass();
        $tracker->renderCount = 0;

        $component = new class ($tracker) implements Component {
            public function __construct(private \stdClass $tracker)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $this->tracker->renderCount++;

                return \Phalanx\Tui\Kit\text('render ' . $this->tracker->renderCount);
            }
        };

        $mounted = $this->createMounted($component);
        $mounted->render($this->createRenderCtx());
        self::assertSame(1, $tracker->renderCount);

        $mounted->markDirty();
        $mounted->rerender();
        self::assertSame(2, $tracker->renderCount);
    }

    #[Test]
    public function disposeCleanupOwnedSignals(): void
    {
        $component = new class () implements Component {
            public function __construct(
                private(set) Signal $count = new Signal(0),
                private(set) Signal $name = new Signal(''),
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        self::assertFalse($component->count->isDisposed);

        $mounted->dispose();

        self::assertTrue($component->count->isDisposed);
        self::assertTrue($component->name->isDisposed);
        self::assertTrue($mounted->isDisposed);
    }

    #[Test]
    public function borrowedSignalsSurviveDispose(): void
    {
        $shared = new Signal('parent-owned');

        $component = new class ($shared) implements Component {
            public function __construct(
                private(set) Signal $input,
                private(set) Signal $local = new Signal(0),
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text((string) $this->input->get());
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch, ['input' => $shared]);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        $mounted->dispose();

        self::assertFalse($shared->isDisposed, 'Borrowed signal must survive child dispose');
        self::assertTrue($component->local->isDisposed, 'Owned signal must be disposed');
    }

    #[Test]
    public function disposeCallsComponentDisposable(): void
    {
        $tracker = new \stdClass();
        $tracker->disposed = false;

        $component = new class ($tracker) implements Component, TuiDisposable {
            public function __construct(private \stdClass $tracker)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }

            public function dispose(): void
            {
                $this->tracker->disposed = true;
            }
        };

        $mounted = $this->createMounted($component);
        $mounted->dispose();

        self::assertTrue($tracker->disposed);
    }

    #[Test]
    public function disposeIsIdempotent(): void
    {
        $tracker = new \stdClass();
        $tracker->count = 0;

        $component = new class ($tracker) implements Component, TuiDisposable {
            public function __construct(private \stdClass $tracker)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }

            public function dispose(): void
            {
                $this->tracker->count++;
            }
        };

        $mounted = $this->createMounted($component);
        $mounted->dispose();
        $mounted->dispose();

        self::assertSame(1, $tracker->count);
    }

    #[Test]
    public function rerenderAfterDisposeIsNoOp(): void
    {
        $tracker = new \stdClass();
        $tracker->renderCount = 0;

        $component = new class ($tracker) implements Component {
            public function __construct(private \stdClass $tracker)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $this->tracker->renderCount++;

                return \Phalanx\Tui\Kit\text('test');
            }
        };

        $mounted = $this->createMounted($component);
        $mounted->render($this->createRenderCtx());
        self::assertSame(1, $tracker->renderCount);

        $mounted->dispose();
        $mounted->rerender();

        self::assertSame(1, $tracker->renderCount);
    }

    #[Test]
    public function renderAfterDisposeReturnsFallback(): void
    {
        $tracker = new \stdClass();
        $tracker->renderCount = 0;

        $component = new class ($tracker) implements Component {
            public function __construct(private \stdClass $tracker)
            {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                $this->tracker->renderCount++;

                return \Phalanx\Tui\Kit\text('test');
            }
        };

        $mounted = $this->createMounted($component);
        $ctx = $this->createRenderCtx();
        $mounted->render($ctx);
        self::assertSame(1, $tracker->renderCount);

        $mounted->dispose();

        $result = $mounted->render($ctx);
        self::assertSame(1, $tracker->renderCount, 'Component __invoke must not run after dispose');
        self::assertInstanceOf(TextElement::class, $result);
    }

    #[Test]
    public function exceptionDuringRenderLeavesTrackerClean(): void
    {
        $component = new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                throw new \RuntimeException('boom');
            }
        };

        $mounted = $this->createMounted($component);

        $frame = Tracker::push();

        try {
            $mounted->render($this->createRenderCtx());
        } catch (\RuntimeException) {
        }

        $deps = Tracker::pop($frame);
        self::assertEmpty($deps, 'Tracker frame must be clean after exception in component render');
    }

    #[Test]
    public function signalCountReflectsOwnedSignals(): void
    {
        $component = new class () implements Component {
            public function __construct(
                private(set) Signal $alpha = new Signal(0),
                private(set) Signal $beta = new Signal(''),
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        self::assertSame(2, $mounted->signalCount);
    }

    #[Test]
    public function subscriptionCountReflectsSubscriptions(): void
    {
        $signal = new Signal(0);

        $component = new class ($signal) implements Component {
            public function __construct(
                private(set) Signal $external,
                private(set) Signal $local = new Signal(''),
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text((string) $this->external->get());
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch, ['external' => $signal]);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        self::assertGreaterThan(0, $mounted->subscriptionCount);
    }

    #[Test]
    public function countsReturnZeroAfterDispose(): void
    {
        $component = new class () implements Component {
            public function __construct(
                private(set) Signal $alpha = new Signal(0),
                private(set) Signal $beta = new Signal(''),
            ) {
            }

            public function __invoke(RenderContext $ctx): Renderable
            {
                return \Phalanx\Tui\Kit\text('test');
            }
        };

        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);
        $mounted = new MountedComponent($component, $batch, $scanResult);

        self::assertSame(2, $mounted->signalCount);

        $mounted->dispose();

        self::assertSame(0, $mounted->signalCount);
        self::assertSame(0, $mounted->subscriptionCount);
    }

    #[Test]
    public function slowRenderEmitsTraceDiagnosticInPlainScope(): void
    {
        $clock = new ClockProbe(1.0, 1.075);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 0.05,
            clock: $clock(...),
        );

        $mounted = $this->createMounted(new RenderDiagnosticSlowComponent());

        $mounted->render($this->createObservedRenderCtx($scope, $diagnostics));

        $events = $scope->trace()->events();

        self::assertSame(0, $scope->callCount());
        self::assertNull($scope->lastWaitReason());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Lifecycle, $events[0]->type);
        self::assertSame('tui.render.slow', $events[0]->name);
        self::assertSame('component', $events[0]->attrs['kind']);
        self::assertSame(RenderDiagnosticSlowComponent::class, $events[0]->attrs['target']);
        self::assertEqualsWithDelta(75.0, $events[0]->attrs['elapsed_ms'], 0.001);
        self::assertTrue($clock->isExhausted());
    }

    #[Test]
    public function failedRenderEmitsTraceDiagnosticAndLeavesComponentDirty(): void
    {
        $clock = new ClockProbe(2.0, 2.002);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 1.0,
            clock: $clock(...),
        );

        $component = new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                throw new \RuntimeException('component failed');
            }
        };
        $mounted = $this->createMounted($component);

        try {
            $mounted->render($this->createObservedRenderCtx($scope, $diagnostics));
            self::fail('Expected failed component render.');
        } catch (\RuntimeException $e) {
            self::assertSame('component failed', $e->getMessage());
        }

        $events = $scope->trace()->events();
        self::assertTrue($mounted->isDirty);
        self::assertSame(0, $scope->callCount());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Failed, $events[0]->type);
        self::assertSame('tui.render.failed', $events[0]->name);
        self::assertSame('component', $events[0]->attrs['kind']);
        self::assertSame(\RuntimeException::class, $events[0]->attrs['error']);
        self::assertSame('component failed', $events[0]->attrs['message']);
        self::assertTrue($clock->isExhausted());
    }

    #[Test]
    public function mountResolutionFailureEmitsTraceDiagnostic(): void
    {
        $clock = new ClockProbe(2.0, 2.003);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 1.0,
            clock: $clock(...),
        );

        $component = new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return mount(RenderDiagnosticFailingChildComponent::class);
            }
        };
        $mounted = $this->createMounted($component);

        try {
            $mounted->render($this->createObservedRenderCtx($scope, $diagnostics));
            self::fail('Expected mount resolution failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('child mount failed', $e->getMessage());
        }

        $events = $scope->trace()->events();
        self::assertTrue($mounted->isDirty);
        self::assertSame(0, $scope->callCount());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Failed, $events[0]->type);
        self::assertSame('tui.render.failed', $events[0]->name);
        self::assertSame('component', $events[0]->attrs['kind']);
        self::assertSame(\RuntimeException::class, $events[0]->attrs['error']);
        self::assertTrue($clock->isExhausted());
    }

    #[Test]
    public function mountCommitFailureEmitsTraceDiagnosticAndLeavesComponentDirty(): void
    {
        $clock = new ClockProbe(2.0, 2.004);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 1.0,
            clock: $clock(...),
        );

        $component = new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                return mount(RenderDiagnosticFailingMountLifecycleChildComponent::class);
            }
        };
        $mounted = $this->createMounted($component);

        try {
            $mounted->render($this->createObservedRenderCtx($scope, $diagnostics));
            self::fail('Expected mount commit failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('child onMount failed', $e->getMessage());
        }

        $events = $scope->trace()->events();
        self::assertTrue($mounted->isDirty);
        self::assertSame(0, $scope->callCount());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Failed, $events[0]->type);
        self::assertSame('tui.render.failed', $events[0]->name);
        self::assertSame('component', $events[0]->attrs['kind']);
        self::assertSame(\RuntimeException::class, $events[0]->attrs['error']);
        self::assertSame('child onMount failed', $events[0]->attrs['message']);
        self::assertTrue($clock->isExhausted());
    }

    #[Test]
    public function cancelledRenderEmitsTraceDiagnosticAndRethrowsCancellation(): void
    {
        $clock = new ClockProbe(3.0, 3.001);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 1.0,
            clock: $clock(...),
        );

        $mounted = $this->createMounted(new class () implements Component {
            public function __invoke(RenderContext $ctx): Renderable
            {
                throw new Cancelled();
            }
        });

        try {
            $mounted->render($this->createObservedRenderCtx($scope, $diagnostics));
            self::fail('Expected cancelled component render.');
        } catch (Cancelled) {
        }

        $events = $scope->trace()->events();
        self::assertTrue($mounted->isDirty);
        self::assertSame(0, $scope->callCount());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Lifecycle, $events[0]->type);
        self::assertSame('tui.render.cancelled', $events[0]->name);
        self::assertSame('component', $events[0]->attrs['kind']);
        self::assertArrayNotHasKey('error', $events[0]->attrs);
        self::assertTrue($clock->isExhausted());
    }

    private function createMounted(Component $component): MountedComponent
    {
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($component, $batch);

        return new MountedComponent($component, $batch, $scanResult);
    }

    private function createRenderCtx(): RenderContext
    {
        $scope = $this->createStub(\Phalanx\Scope\Scope::class);

        return new RenderContext(
            $scope,
            Theme::default(),
            new MountSystem($scope),
        );
    }

    private function createObservedRenderCtx(
        RecordingTaskScope $scope,
        RenderDiagnostics $diagnostics,
    ): RenderContext {
        return new RenderContext(
            $scope,
            Theme::default(),
            new MountSystem($scope),
            renderDiagnostics: $diagnostics,
        );
    }
}

final class RenderDiagnosticFailingChildComponent implements Component
{
    public function __construct()
    {
        throw new \RuntimeException('child mount failed');
    }

    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Kit\text('unreachable');
    }
}

final class RenderDiagnosticFailingMountLifecycleChildComponent implements Component, Mountable
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Kit\text('child');
    }

    public function onMount(TaskScope $scope): void
    {
        throw new \RuntimeException('child onMount failed');
    }

    public function onUnmount(): void
    {
    }
}

final class RenderDiagnosticSlowComponent implements Component
{
    public function __invoke(RenderContext $ctx): Renderable
    {
        return \Phalanx\Tui\Kit\text('slow');
    }
}
