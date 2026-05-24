<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Component;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Component\MountedScreen;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Component\SignalScanner;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Reactive\Computed;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactive\Resource;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Rendering\RenderDiagnostics;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tests\Support\ClockProbe;
use Phalanx\Theatron\Tests\Support\RecordingTaskScope;
use Phalanx\Trace\TraceType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MountedScreenTest extends TestCase
{
    #[Test]
    public function renderTimeConditionalDependenciesAreReconciled(): void
    {
        $model = new \stdClass();
        $model->useA = new Signal(true);
        $model->a = new Signal('a');
        $model->b = new Signal('b');

        $screen = new class ($model) implements Screen {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(ScreenContext $ctx): Renderable
            {
                $value = $this->model->useA->get()
                    ? $this->model->a->get()
                    : $this->model->b->get();

                return \Phalanx\Theatron\Ui\text((string) $value);
            }
        };

        $mounted = $this->createMounted($screen);
        $ctx = $this->createScreenCtx();

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->useA->set(false);
        self::assertTrue($mounted->isDirty);

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->a->set('old branch');
        self::assertFalse($mounted->isDirty);

        $model->b->set('new branch');
        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function failedRenderLeavesScreenDirtyAndPreservesPreviousDependencies(): void
    {
        $model = new \stdClass();
        $model->throw = false;
        $model->good = new Signal('good');
        $model->bad = new Signal('bad');

        $screen = new class ($model) implements Screen {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(ScreenContext $ctx): Renderable
            {
                if ($this->model->throw) {
                    $this->model->bad->get();

                    throw new \RuntimeException('screen failed');
                }

                return \Phalanx\Theatron\Ui\text((string) $this->model->good->get());
            }
        };

        $mounted = $this->createMounted($screen);
        $ctx = $this->createScreenCtx();
        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->throw = true;

        try {
            $mounted->render($ctx);
            self::fail('Expected failed screen render.');
        } catch (\RuntimeException $e) {
            self::assertSame('screen failed', $e->getMessage());
        }

        self::assertTrue($mounted->isDirty);
        $model->throw = false;

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->bad->set('bad changed');
        self::assertFalse($mounted->isDirty);

        $model->good->set('good changed');
        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function renderTimeResourceAndComputedDependenciesMarkScreenDirty(): void
    {
        $model = new \stdClass();
        $model->source = new Signal('first');
        $model->label = new Computed(static fn(): string => strtoupper((string) $model->source->get()));
        $model->reply = new Resource(static fn(): iterable => ['streamed']);

        $screen = new class ($model) implements Screen {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(ScreenContext $ctx): Renderable
            {
                return \Phalanx\Theatron\Ui\text($this->model->label->value . ':' . $this->model->reply->buffer);
            }
        };

        $mounted = $this->createMounted($screen);
        $ctx = $this->createScreenCtx();
        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->reply->stream();
        self::assertTrue($mounted->isDirty);

        $mounted->render($ctx);
        self::assertFalse($mounted->isDirty);

        $model->source->set('second');
        self::assertTrue($mounted->isDirty);
    }

    #[Test]
    public function disposeCleansScreenRenderTimeDependencies(): void
    {
        $model = new \stdClass();
        $model->signal = new Signal('active');

        $screen = new class ($model) implements Screen {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(ScreenContext $ctx): Renderable
            {
                return \Phalanx\Theatron\Ui\text((string) $this->model->signal->get());
            }
        };

        $mounted = $this->createMounted($screen);
        $mounted->render($this->createScreenCtx());
        self::assertSame(1, $model->signal->subscriberCount);

        $mounted->dispose();

        self::assertSame(0, $model->signal->subscriberCount);
    }

    #[Test]
    public function failedReconcileLeavesPreviousScreenRenderDependenciesSubscribed(): void
    {
        $model = new \stdClass();
        $model->useBad = new Signal(false);
        $model->good = new Signal('good');
        $model->bad = new Signal('bad');
        $model->bad->dispose();

        $screen = new class ($model) implements Screen {
            public function __construct(private \stdClass $model)
            {
            }

            public function __invoke(ScreenContext $ctx): Renderable
            {
                $value = $this->model->useBad->get()
                    ? $this->model->bad->get()
                    : $this->model->good->get();

                return \Phalanx\Theatron\Ui\text((string) $value);
            }
        };

        $mounted = $this->createMounted($screen);
        $ctx = $this->createScreenCtx();
        $mounted->render($ctx);
        self::assertSame(1, $model->good->subscriberCount);

        $model->useBad->set(true);

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
    public function slowScreenRenderEmitsTraceDiagnosticInPlainScope(): void
    {
        $clock = new ClockProbe(1.0, 1.08);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 0.05,
            clock: $clock(...),
        );

        $mounted = $this->createMounted(new RenderDiagnosticSlowScreen());

        $mounted->render($this->createObservedScreenCtx($scope, $diagnostics));

        $events = $scope->trace()->events();

        self::assertSame(0, $scope->callCount());
        self::assertNull($scope->lastWaitReason());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Lifecycle, $events[0]->type);
        self::assertSame('theatron.render.slow', $events[0]->name);
        self::assertSame('screen', $events[0]->attrs['kind']);
        self::assertSame(RenderDiagnosticSlowScreen::class, $events[0]->attrs['target']);
        self::assertEqualsWithDelta(80.0, $events[0]->attrs['elapsed_ms'], 0.001);
        self::assertTrue($clock->isExhausted());
    }

    #[Test]
    public function failedScreenRenderEmitsTraceDiagnosticAndLeavesScreenDirty(): void
    {
        $clock = new ClockProbe(2.0, 2.004);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 1.0,
            clock: $clock(...),
        );

        $mounted = $this->createMounted(new class () implements Screen {
            public function __invoke(ScreenContext $ctx): Renderable
            {
                throw new \RuntimeException('screen failed');
            }
        });

        try {
            $mounted->render($this->createObservedScreenCtx($scope, $diagnostics));
            self::fail('Expected failed screen render.');
        } catch (\RuntimeException $e) {
            self::assertSame('screen failed', $e->getMessage());
        }

        $events = $scope->trace()->events();
        self::assertTrue($mounted->isDirty);
        self::assertSame(0, $scope->callCount());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Failed, $events[0]->type);
        self::assertSame('theatron.render.failed', $events[0]->name);
        self::assertSame('screen', $events[0]->attrs['kind']);
        self::assertSame(\RuntimeException::class, $events[0]->attrs['error']);
        self::assertSame('screen failed', $events[0]->attrs['message']);
        self::assertTrue($clock->isExhausted());
    }

    #[Test]
    public function cancelledScreenRenderEmitsTraceDiagnosticAndRethrowsCancellation(): void
    {
        $clock = new ClockProbe(3.0, 3.001);
        $scope = new RecordingTaskScope();
        $diagnostics = RenderDiagnostics::enabled(
            slowThresholdSeconds: 1.0,
            clock: $clock(...),
        );

        $mounted = $this->createMounted(new class () implements Screen {
            public function __invoke(ScreenContext $ctx): Renderable
            {
                throw new Cancelled();
            }
        });

        try {
            $mounted->render($this->createObservedScreenCtx($scope, $diagnostics));
            self::fail('Expected cancelled screen render.');
        } catch (Cancelled) {
        }

        $events = $scope->trace()->events();
        self::assertTrue($mounted->isDirty);
        self::assertSame(0, $scope->callCount());
        self::assertCount(1, $events);
        self::assertSame(TraceType::Lifecycle, $events[0]->type);
        self::assertSame('theatron.render.cancelled', $events[0]->name);
        self::assertSame('screen', $events[0]->attrs['kind']);
        self::assertArrayNotHasKey('error', $events[0]->attrs);
        self::assertTrue($clock->isExhausted());
    }

    private function createMounted(Screen $screen): MountedScreen
    {
        $batch = new DirtyBatch();
        $scanResult = SignalScanner::scan($screen, $batch);

        return new MountedScreen($screen, $batch, $scanResult);
    }

    private function createScreenCtx(): ScreenContext
    {
        $scope = $this->createStub(TaskScope::class);

        return new ScreenContext(
            $scope,
            Theme::default(),
            $this->createStub(Navigator::class),
            new MountSystem($scope),
        );
    }

    private function createObservedScreenCtx(
        RecordingTaskScope $scope,
        RenderDiagnostics $diagnostics,
    ): ScreenContext {
        return new ScreenContext(
            $scope,
            Theme::default(),
            $this->createStub(Navigator::class),
            new MountSystem($scope),
            $diagnostics,
        );
    }
}

final class RenderDiagnosticSlowScreen implements Screen
{
    public function __invoke(ScreenContext $ctx): Renderable
    {
        return \Phalanx\Theatron\Ui\text('slow');
    }
}
