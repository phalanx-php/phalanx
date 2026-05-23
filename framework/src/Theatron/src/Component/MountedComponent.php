<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Scope\TaskScope;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Context\RenderEnvironment;
use Phalanx\Theatron\Contract\Component;
use Phalanx\Theatron\Contract\Disposable as TheatronDisposable;
use Phalanx\Theatron\Contract\Mountable;
use Phalanx\Theatron\Contract\Styled;
use Phalanx\Theatron\Reactive\DirtyBatch;
use Phalanx\Theatron\Reactive\RenderDependencySet;
use Phalanx\Theatron\Reactive\ResourceSubscription;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\SignalSubscription;
use Phalanx\Theatron\Reactive\Tracker;
use Phalanx\Theatron\State\StoreSubscription;
use Phalanx\Theatron\Styling\Stylesheet;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Style;
use Throwable;
use function Phalanx\Theatron\Ui\text;

final class MountedComponent implements Renderable
{
    private(set) bool $isDisposed = false;

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
        get => count($this->ownedSignals);
    }

    /** Active subscriptions include constructor-scanned and render-time dependencies. */
    public int $subscriptionCount {
        get => count($this->subscriptions) + $this->renderDependencies->count;
    }

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

        foreach ($this->storeSubscriptions as $sub) {
            $sub->dispose();
        }
        $this->storeSubscriptions = [];

        $this->renderDependencies->dispose();

        foreach ($this->ownedSignals as $signal) {
            $signal->dispose();
        }
        $this->ownedSignals = [];

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
