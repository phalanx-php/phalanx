<?php

declare(strict_types=1);

namespace Phalanx\Theatron;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Binding\Binding;
use Phalanx\Theatron\Binding\BindingRegistry;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Context\RenderContext;
use Phalanx\Theatron\Context\RenderEnvironment;
use Phalanx\Theatron\Context\ScreenContext;
use Phalanx\Theatron\Contract\DeclaresBindings;
use Phalanx\Theatron\Contract\HandlesKeySequences;
use Phalanx\Theatron\Contract\HasActivityPulse;
use Phalanx\Theatron\Contract\HasFocusables;
use Phalanx\Theatron\Contract\HasKeySequenceState;
use Phalanx\Theatron\Contract\HasOverlayFrame;
use Phalanx\Theatron\Contract\HasStatusBar;
use Phalanx\Theatron\Contract\HasWorkspaceInputModes;
use Phalanx\Theatron\Contract\PreparesWorkspaceDraw;
use Phalanx\Theatron\Contract\ProvidesMountServices;
use Phalanx\Theatron\Contract\RefreshesPeriodically;
use Phalanx\Theatron\Contract\Screen;
use Phalanx\Theatron\Focus\FocusManager;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputMode;
use Phalanx\Theatron\Input\InputModeSlice;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Input\ModeDispatcher;
use Phalanx\Theatron\Input\NormalModeHandler;
use Phalanx\Theatron\Kit\ScreenLayout;
use Phalanx\Theatron\Navigation\Navigator;
use Phalanx\Theatron\Navigation\WorkspaceNavigator;
use Phalanx\Theatron\Overlay\OverlayFrame;
use Phalanx\Theatron\Overlay\OverlayPainter;
use Phalanx\Theatron\Reactive\SignalRegistry;
use Phalanx\Theatron\Rendering\Region;
use Phalanx\Theatron\Rendering\RenderDiagnostics;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\State\Store;
use Phalanx\Theatron\Styling\Theme;
use Phalanx\Theatron\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Tdom\Renderable;

final class TheatronApp
{
    /**
     * @param list<class-string<Screen>> $screens
     * @param list<Binding> $globalBindings
     * @param class-string<Store>|null $storeClass
     */
    public function __construct(
        private(set) Stage $stage,
        private(set) Theme $theme,
        private(set) array $screens,
        private(set) array $globalBindings,
        private(set) ?string $storeClass,
        private(set) bool $devtools,
        private(set) ?SignalRegistry $registry = null,
    ) {
    }

    public function start(ExecutionScope $scope): void
    {
        $registry = new BindingRegistry();
        $registry->setGlobal($this->globalBindings);

        $mountSystem = new MountSystem($scope, registry: $this->registry);
        $mountSystem->provide(MountSystem::class, $mountSystem);
        $mountSystem->provide(BindingRegistry::class, $registry);

        if ($this->registry !== null) {
            $mountSystem->provide(SignalRegistry::class, $this->registry);
        }

        if ($this->storeClass !== null) {
            try {
                $store = $scope->service($this->storeClass);
            } catch (ServiceNotFoundException) {
                $store = new ($this->storeClass)();
            }

            $mountSystem->provide($this->storeClass, $store);
            $mountSystem->provide(Store::class, $store);
        } else {
            $store = null;
        }

        if ($store instanceof ProvidesMountServices) {
            $store->provideMountServices($mountSystem, $scope);
        }

        $navigator = new WorkspaceNavigator($mountSystem, $this->screens[0]);
        $mountSystem->provide(Navigator::class, $navigator);
        $registry->activateScreen($this->screens[0]);
        self::rebuildBindings($registry, $navigator);

        $theme = $this->theme;
        $renderDiagnostics = RenderDiagnostics::enabled();
        $renderCtx = new RenderContext($scope, $this->theme, $mountSystem, $registry, $renderDiagnostics);
        $statusMountOwner = new \stdClass();

        $layout = ScreenLayout::mainWithStatusBar();
        $layout->attach($this->stage);

        $focus = new FocusManager();
        $dispatcher = new ModeDispatcher($focus);

        if ($store !== null) {
            $dispatcher->onModeChange(static function (InputMode $mode, ?string $focusTarget) use ($store, $navigator): void {
                $store->mutate(
                    InputModeSlice::class,
                    static fn(InputModeSlice $_) => new InputModeSlice($mode, $focusTarget),
                );

                if ($store instanceof HasWorkspaceInputModes) {
                    $workspace = $navigator->active();
                    $store->saveInputModeForWorkspace($workspace, $mode, $focusTarget);
                }
            });
        }

        self::restoreFocusAndMode($focus, $dispatcher, $navigator, $store);

        $lastActivityPulseAt = 0.0;
        $lastScreenRefreshAt = 0.0;
        $lastMainWidth = null;
        $lastMainHeight = null;

        $this->stage->onDraw(static function () use (
            $scope,
            $theme,
            $mountSystem,
            $navigator,
            $layout,
            $renderCtx,
            $renderDiagnostics,
            $statusMountOwner,
            $store,
            &$lastActivityPulseAt,
            &$lastScreenRefreshAt,
            &$lastMainWidth,
            &$lastMainHeight,
        ): void {
            $workspace = $navigator->activeWorkspace();
            $now = microtime(true);
            $mainRegion = $layout->region('main');
            $mainWidth = $mainRegion->area->width;
            $mainHeight = $mainRegion->area->height;
            $viewportChanged = $mainWidth !== $lastMainWidth || $mainHeight !== $lastMainHeight;

            if ($store instanceof PreparesWorkspaceDraw) {
                $store->prepareWorkspaceDraw($navigator);
            }

            if ($store instanceof HasActivityPulse) {
                if ($store->activityIsBusy() && $now - $lastActivityPulseAt >= 0.25) {
                    $store->tickActivity();
                    $workspace->markDirty();
                    $lastActivityPulseAt = $now;
                }
            }

            $refreshInterval = self::refreshIntervalSeconds($workspace->screen);
            if ($refreshInterval !== null && $now - $lastScreenRefreshAt >= $refreshInterval) {
                $workspace->markDirty();
                $lastScreenRefreshAt = $now;
            }

            $statusIsDirty = $mountSystem->hasDirtyOwnedSlots($statusMountOwner);
            $overlays = $navigator->overlays();
            $topOverlay = $overlays !== [] ? $overlays[array_key_last($overlays)] : null;
            $overlayIsDirty = $topOverlay !== null && ($topOverlay->isDirty || $topOverlay->lastResult() === null);

            if (
                !$workspace->isDirty
                && !$mountSystem->hasDirtyOwnedSlots($workspace)
                && !$statusIsDirty
                && !$overlayIsDirty
                && !$viewportChanged
            ) {
                return;
            }

            $screenCtx = new ScreenContext(
                $scope,
                $theme,
                $navigator,
                $mountSystem,
                $renderDiagnostics,
                width: $mainWidth,
                height: $mainHeight,
            );

            $renderable = $workspace->isDirty || $workspace->lastResult() === null || $viewportChanged
                ? $workspace->render($screenCtx)
                : $workspace->lastResult();
            $lastMainWidth = $mainWidth;
            $lastMainHeight = $mainHeight;

            self::paintRegion($renderable, $mainRegion, $renderCtx, $workspace);

            if ($topOverlay !== null) {
                $overlayRenderable = $topOverlay->isDirty || $topOverlay->lastResult() === null
                    ? $topOverlay->render($renderCtx)
                    : $topOverlay->lastResult();

                self::paintOverlay($overlayRenderable, $mainRegion, $renderCtx, $topOverlay);
            }

            $statusRegion = $layout->region('status');
            $screen = $workspace->screen;
            $statusBar = $topOverlay?->component instanceof HasStatusBar
                ? $topOverlay->component
                : ($screen instanceof HasStatusBar ? $screen : null);

            if ($statusBar instanceof HasStatusBar) {
                $statusRenderable = RenderEnvironment::withTheme(
                    $screenCtx->theme,
                    static fn(): Renderable => $statusBar->statusBar(),
                );
                self::paintRegion(
                    $statusRenderable,
                    $statusRegion,
                    $renderCtx,
                    $topOverlay?->component instanceof HasStatusBar ? $topOverlay : $statusMountOwner,
                );
            } else {
                $mountSystem->disposeOwnedSlots($statusMountOwner);
                $statusRegion->buffer()->clear();
                $statusRegion->markDirty();
            }
        });

        $stage = $this->stage;
        $this->stage->onInput(
            static function (InputEvent $event) use ($registry, $navigator, $scope, $focus, $dispatcher, $stage, $store): void {
                if (!$event instanceof KeyEvent) {
                    return;
                }

                $overlays = $navigator->overlays();
                $topOverlay = $overlays !== [] ? $overlays[array_key_last($overlays)] : null;

                if (
                    $topOverlay?->component instanceof NormalModeHandler
                    && $topOverlay->component->handleNormalKey($event)
                ) {
                    $stage->requestFrame();

                    return;
                }

                if (
                    $store instanceof HasKeySequenceState
                    && $store->keySequenceState()->isAwaitingControlX()
                    && self::dispatchKeySequence($event, $store, $navigator)
                ) {
                    $stage->requestFrame();

                    return;
                }

                $binding = $registry->resolve($event);

                if ($binding !== null) {
                    $action = $binding->action;

                    if ($action !== null) {
                        if ($action->isQuit()) {
                            $stage->requestFrame();
                            $scope->cancellation()->cancel();

                            return;
                        }

                        if ($action->isWorkspace()) {
                            /** @var class-string<Screen> $target */
                            $target = $action->target;
                            $navigator->go($target);
                            $registry->activateScreen($target);
                            self::rebuildBindings($registry, $navigator);
                            self::restoreFocusAndMode($focus, $dispatcher, $navigator, $store);
                            $stage->requestFrame();

                            return;
                        }

                        if ($action->isBack()) {
                            if ($navigator->back()) {
                                $registry->activateScreen($navigator->active());
                                self::rebuildBindings($registry, $navigator);
                                self::restoreFocusAndMode($focus, $dispatcher, $navigator, $store);
                                $stage->requestFrame();
                            }

                            return;
                        }

                        if ($action->isAction() && $action->callback !== null) {
                            ($action->callback)();
                            $stage->requestFrame();

                            return;
                        }

                        if ($action->isToggle() && $action->target !== null) {
                            /** @var class-string<\Phalanx\Theatron\Contract\Component> $target */
                            $target = $action->target;

                            if ($navigator->hasOverlays()) {
                                $navigator->dismiss();
                            } else {
                                $navigator->overlay($target);
                            }
                            $stage->requestFrame();

                            return;
                        }
                    }
                }

                $activeBeforeDispatch = $navigator->active();

                if ($store instanceof HasKeySequenceState && self::dispatchKeySequence($event, $store, $navigator)) {
                    $stage->requestFrame();

                    return;
                }

                $handled = $dispatcher->dispatch($event);

                if ($handled) {
                    $stage->requestFrame();
                }

                if ($navigator->active() !== $activeBeforeDispatch) {
                    $registry->activateScreen($navigator->active());
                    self::rebuildBindings($registry, $navigator);
                    self::restoreFocusAndMode($focus, $dispatcher, $navigator, $store);
                    $stage->requestFrame();
                }
            },
        );

        $this->stage->start($scope);

        try {
            while (!$scope->isCancelled) {
                $scope->delay(0.1);
            }
        } catch (Cancelled $e) {
            if (!$scope->isCancelled) {
                throw $e;
            }
        }
    }

    /** @return list<class-string<Screen>> */
    public function screens(): array
    {
        return $this->screens;
    }

    /** @return list<Binding> */
    public function globalBindings(): array
    {
        return $this->globalBindings;
    }

    private static function restoreFocusAndMode(
        FocusManager $focus,
        ModeDispatcher $dispatcher,
        WorkspaceNavigator $navigator,
        ?Store $store,
    ): void {
        self::rebuildFocus($focus, $navigator, $store);

        if (!$store instanceof HasWorkspaceInputModes) {
            $dispatcher->syncModeWithActiveFocus();

            return;
        }

        $saved = $store->inputModeForWorkspace($navigator->active());

        if ($saved === null) {
            $dispatcher->syncModeWithActiveFocus();

            return;
        }

        $dispatcher->restore($saved->mode, $saved->focusTarget);
    }

    private static function dispatchKeySequence(
        KeyEvent $event,
        HasKeySequenceState $store,
        WorkspaceNavigator $navigator,
    ): bool {
        $screen = $navigator->activeWorkspace()->screen;

        if (!$screen instanceof HandlesKeySequences) {
            if (!$store->keySequenceState()->isAwaitingControlX()) {
                return false;
            }

            $store->updateKeySequence($store->keySequenceState()->clear());

            return true;
        }

        if ($store->keySequenceState()->isAwaitingControlX()) {
            $screen->handleKeySequence($store->keySequenceState(), $event);
            $store->updateKeySequence($store->keySequenceState()->clear());

            return true;
        }

        if (!$screen->startsKeySequence($event)) {
            return false;
        }

        $store->updateKeySequence($store->keySequenceState()->beginControlX());

        return true;
    }

    private static function rebuildFocus(FocusManager $focus, WorkspaceNavigator $navigator, ?Store $store): void
    {
        $focus->reset();
        $screen = $navigator->activeWorkspace()->screen;

        if ($screen instanceof HasFocusables) {
            foreach ($screen->focusables() as [$name, $focusable]) {
                $focus->register($name, $focusable);
            }

            $saved = $store instanceof HasWorkspaceInputModes
                ? $store->inputModeForWorkspace($navigator->active())
                : null;

            if ($saved?->focusTarget !== null && in_array($saved->focusTarget, $focus->names(), true)) {
                $focus->focus($saved->focusTarget);
            } elseif (in_array('input', $focus->names(), true)) {
                $focus->focus('input');
            }
        }
    }

    private static function rebuildBindings(BindingRegistry $registry, WorkspaceNavigator $navigator): void
    {
        $screen = $navigator->activeWorkspace()->screen;

        if ($screen instanceof DeclaresBindings) {
            $registry->setScreen($screen::class, $screen->bindings());
        }
    }

    private static function refreshIntervalSeconds(Screen $screen): ?float
    {
        if (!$screen instanceof RefreshesPeriodically) {
            return null;
        }

        $interval = $screen->refreshIntervalSeconds();

        return $interval !== null && $interval > 0 ? $interval : null;
    }

    private static function paintRegion(
        Renderable $renderable,
        Region $region,
        RenderContext $renderCtx,
        object $mountOwner,
    ): void {
        $scratch = Buffer::empty($region->area->width, $region->area->height);

        Painter::paint(
            $renderable,
            new PaintContext(
                Rect::sized($region->area->width, $region->area->height),
                $scratch,
                renderContext: $renderCtx,
                mountOwner: $mountOwner,
            ),
        );

        $region->buffer()->clear();
        $region->buffer()->blitFull($scratch, 0, 0);
        $region->markDirty();
    }

    private static function paintOverlay(
        Renderable $renderable,
        Region $region,
        RenderContext $renderCtx,
        object $mountOwner,
    ): void {
        $bounds = Rect::sized($region->area->width, $region->area->height);
        $frame = $mountOwner instanceof MountedComponent
            && $mountOwner->component instanceof HasOverlayFrame
                ? $mountOwner->component->overlayFrame($bounds)
                : OverlayFrame::fullscreen($bounds);

        OverlayPainter::paint(
            $renderable,
            $region->buffer(),
            $bounds,
            $frame,
            $renderCtx,
            $mountOwner,
        );
        $region->markDirty();
    }
}
