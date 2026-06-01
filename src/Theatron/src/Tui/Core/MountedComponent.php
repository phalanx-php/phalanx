<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Core;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Tui\Core\Component;
use Phalanx\Theatron\Tui\Core\Disposable as TheatronDisposable;
use Phalanx\Theatron\Tui\Core\Mountable;
use Phalanx\Theatron\Tui\Core\RenderContext;
use Phalanx\Theatron\Tui\Core\RenderEnvironment;
use Phalanx\Theatron\Tui\Core\Styled;
use Phalanx\Theatron\Tui\Reactive\DirtyBatch;
use Phalanx\Theatron\Tui\Reactive\RenderDependencySet;
use Phalanx\Theatron\Tui\Reactive\ResourceSubscription;
use Phalanx\Theatron\Tui\Reactive\Signal;
use Phalanx\Theatron\Tui\Reactive\SignalSubscription;
use Phalanx\Theatron\Tui\Reactive\StoreSubscription;
use Phalanx\Theatron\Tui\Reactive\Tracker;
use Phalanx\Theatron\Tui\Styles\Stylesheet;
use Phalanx\Theatron\Tui\Styles\Theme;
use Phalanx\Theatron\Tui\Tdom\Renderable;
use Phalanx\Theatron\Tui\Tdom\Style;
use Throwable;

use function Phalanx\Theatron\Tui\Kit\text;

final class MountedComponent implements Renderable
{
    /** Component-local style is supplied through stylesheet() when the component supports it. */
    public ?Style $style {
        get => null;
    }

    /** Dirty state is owned by the render dependency batch. */
    public bool $isDirty {
        get => $this->dirty->isDirty;
    }

    /** Signal ownership is scanned once at mount time. */
    public int $signalCount {
        get {
            return $this->ownedSignalCount;
        }
    }

    /** Active subscriptions include constructor-scanned and render-time dependencies. */
    public int $subscriptionCount {
        get {
            return $this->subscriptionBaseCount + $this->renderDependencies->count;
        }
    }

    private(set) bool $isDisposed = false;

    private ?Renderable $lastResult = null;
    private ?RenderContext $renderCtx = null;
    private ?Stylesheet $cachedStylesheet = null;
    private ?Theme $cachedTheme = null;
    private bool $mountLifecycleStarted = false;

    /** @var list<Signal> */
    private array $ownedSignals;

    /** @var list<SignalSubscription|ResourceSubscription> */
    private array $subscriptions;

    /** @var list<StoreSubscription> */
    private array $storeSubscriptions;

    private int $ownedSignalCount;

    private int $subscriptionBaseCount;

    private RenderDependencySet $renderDependencies;

    public function __construct(
        private(set) Component $component,
        private(set) DirtyBatch $dirty,
        SignalScanResult $scanResult,
    ) {
        $this->renderDependencies = new RenderDependencySet($this->dirty, $scanResult->renderIgnoredReactives);
        $this->ownedSignals = $scanResult->ownedSignals;
        $this->subscriptions = $scanResult->subscriptions;
        $this->storeSubscriptions = $scanResult->storeSubscriptions;
        $this->ownedSignalCount = \count($this->ownedSignals);
        $this->subscriptionBaseCount = \count($this->subscriptions);
        $this->dirty->request();
    }

    public function activate(TaskScope $scope): void
    {
        if ($this->mountLifecycleStarted || !$this->component instanceof Mountable) {
            return;
        }

        $this->component->onMount($scope);
        $this->mountLifecycleStarted = true;
    }

    public function render(RenderContext $ctx): Renderable
    {
        if ($this->isDisposed) {
            return $this->lastResult ?? text('');
        }

        $this->renderCtx = $ctx;
        $this->dirty->consume();

        if ($this->component instanceof Styled && $this->cachedTheme !== $ctx->theme) {
            $this->cachedStylesheet = $this->component->stylesheet($ctx->theme);
            $this->cachedTheme = $ctx->theme;
        }

        $component = $this->component;
        $mounted = $this;
        try {
            return $ctx->renderDiagnostics->component(
                $ctx->scope,
                $component,
                static fn(): Renderable => RenderEnvironment::withTheme(
                    $ctx->theme,
                    static function () use ($ctx, $component, $mounted): Renderable {
                        $ctx->mountSystem->enterFrame($mounted);
                        $commitMountFrame = false;
                        try {
                            $frame = Tracker::push();
                            $popped = false;
                            try {
                                $result = $ctx->mountSystem->resolve(
                                    $component($ctx),
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

    public function stylesheet(): ?Stylesheet
    {
        return $this->cachedStylesheet;
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
        $this->subscriptionBaseCount = 0;

        foreach ($this->storeSubscriptions as $sub) {
            $sub->dispose();
        }
        $this->storeSubscriptions = [];

        $this->renderDependencies->dispose();

        foreach ($this->ownedSignals as $signal) {
            $signal->dispose();
        }
        $this->ownedSignals = [];
        $this->ownedSignalCount = 0;

        if ($this->mountLifecycleStarted && $this->component instanceof Mountable) {
            $this->component->onUnmount();
            $this->mountLifecycleStarted = false;
        }

        if ($this->component instanceof TheatronDisposable) {
            $this->component->dispose();
        }

        $this->lastResult = null;
        $this->renderCtx = null;
        $this->cachedStylesheet = null;
        $this->cachedTheme = null;
    }
}
