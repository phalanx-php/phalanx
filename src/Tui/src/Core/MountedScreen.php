<?php

declare(strict_types=1);

namespace Phalanx\Tui\Core;

use Phalanx\Scope\TaskScope;
use Phalanx\Tui\Core\Disposable as TuiDisposable;
use Phalanx\Tui\Core\Mountable;
use Phalanx\Tui\Core\RenderEnvironment;
use Phalanx\Tui\Core\Screen;
use Phalanx\Tui\Core\ScreenContext;
use Phalanx\Tui\Reactive\DirtyBatch;
use Phalanx\Tui\Reactive\RenderDependencySet;
use Phalanx\Tui\Reactive\ResourceSubscription;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Reactive\SignalSubscription;
use Phalanx\Tui\Reactive\StoreSubscription;
use Phalanx\Tui\Reactive\Tracker;
use Phalanx\Tui\Tdom\Renderable;
use Throwable;

use function Phalanx\Tui\Kit\text;

final class MountedScreen
{
    private(set) bool $isDisposed = false;

    /** Dirty state is owned by the screen render dependency batch. */
    public bool $isDirty {
        get => $this->dirty->isDirty;
    }

    private ?Renderable $lastResult = null;
    private ?ScreenContext $renderCtx = null;
    private RenderDependencySet $renderDependencies;

    /** @var list<Signal> */
    private array $ownedSignals;

    /** @var list<SignalSubscription|ResourceSubscription> */
    private array $subscriptions;

    /** @var list<StoreSubscription> */
    private array $storeSubscriptions;

    private bool $mountLifecycleStarted = false;

    public function __construct(
        private(set) Screen $screen,
        private(set) DirtyBatch $dirty,
        SignalScanResult $scanResult,
    ) {
        $this->renderDependencies = new RenderDependencySet($this->dirty, $scanResult->renderIgnoredReactives);
        $this->ownedSignals = $scanResult->ownedSignals;
        $this->subscriptions = $scanResult->subscriptions;
        $this->storeSubscriptions = $scanResult->storeSubscriptions;
        $this->dirty->request();
    }

    public function activate(TaskScope $scope): void
    {
        if ($this->mountLifecycleStarted || !$this->screen instanceof Mountable) {
            return;
        }

        $this->screen->onMount($scope);
        $this->mountLifecycleStarted = true;
    }

    public function render(ScreenContext $ctx): Renderable
    {
        if ($this->isDisposed) {
            return $this->lastResult ?? text('');
        }

        $this->renderCtx = $ctx;
        $this->dirty->consume();

        $screen = $this->screen;
        $mounted = $this;
        try {
            return $ctx->renderDiagnostics->screen(
                $ctx->scope,
                $screen,
                static fn(): Renderable => RenderEnvironment::withTheme(
                    $ctx->theme,
                    static function () use ($ctx, $screen, $mounted): Renderable {
                        $ctx->mountSystem->enterFrame($mounted);
                        $commitMountFrame = false;
                        try {
                            $frame = Tracker::push();
                            $popped = false;
                            try {
                                $result = $ctx->mountSystem->resolve(
                                    $screen($ctx),
                                );
                                $deps = Tracker::pop($frame);
                                $popped = true;
                            } finally {
                                if (!$popped) {
                                    Tracker::pop($frame);
                                }
                            }

                            $mounted->renderDependencies->reconcile($deps);
                            $mounted->lastResult = $result;
                            $commitMountFrame = true;

                            return $result;
                        } finally {
                            $ctx->mountSystem->leaveFrame($mounted, $commitMountFrame);
                        }
                    },
                ),
            );
        } catch (Throwable $e) {
            $this->dirty->request();

            throw $e;
        }
    }

    public function rerender(): void
    {
        if ($this->renderCtx !== null && !$this->isDisposed) {
            $this->render($this->renderCtx);
        }
    }

    public function lastResult(): ?Renderable
    {
        return $this->lastResult;
    }

    public function markDirty(): void
    {
        $this->dirty->request();
    }

    public function dispose(): void
    {
        if ($this->isDisposed) {
            return;
        }

        $this->isDisposed = true;

        $this->renderCtx?->mountSystem->disposeOwnedSlots($this);

        foreach ($this->subscriptions as $sub) {
            $sub->dispose();
        }
        $this->subscriptions = [];

        foreach ($this->storeSubscriptions as $sub) {
            $sub->dispose();
        }
        $this->storeSubscriptions = [];

        $this->renderDependencies->dispose();

        foreach ($this->ownedSignals as $signal) {
            $signal->dispose();
        }
        $this->ownedSignals = [];

        if ($this->mountLifecycleStarted && $this->screen instanceof Mountable) {
            $this->screen->onUnmount();
            $this->mountLifecycleStarted = false;
        }

        if ($this->screen instanceof TuiDisposable) {
            $this->screen->dispose();
        }

        $this->lastResult = null;
        $this->renderCtx = null;
    }
}
