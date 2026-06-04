<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Apps;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Mark\Mark;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Tui\Core\DeclaresBindings;
use Phalanx\Theatron\Tui\Core\HandlesKeySequences;
use Phalanx\Theatron\Tui\Core\HasActivityPulse;
use Phalanx\Theatron\Tui\Core\HasFocusables;
use Phalanx\Theatron\Tui\Core\HasKeySequenceState;
use Phalanx\Theatron\Tui\Core\HasOverlayFrame;
use Phalanx\Theatron\Tui\Core\HasStatusBar;
use Phalanx\Theatron\Tui\Core\HasWorkspaceInputModes;
use Phalanx\Theatron\Tui\Core\MountedComponent;
use Phalanx\Theatron\Tui\Core\MountSystem;
use Phalanx\Theatron\Tui\Core\PreparesWorkspaceDraw;
use Phalanx\Theatron\Tui\Core\ProvidesMountServices;
use Phalanx\Theatron\Tui\Core\RefreshesPeriodically;
use Phalanx\Theatron\Tui\Core\RenderContext;
use Phalanx\Theatron\Tui\Core\RenderEnvironment;
use Phalanx\Theatron\Tui\Core\Screen;
use Phalanx\Theatron\Tui\Core\ScreenContext;
use Phalanx\Theatron\Tui\Drawing\Buffer;
use Phalanx\Theatron\Tui\Drawing\Rect;
use Phalanx\Theatron\Tui\Drawing\Region;
use Phalanx\Theatron\Tui\Drawing\RenderDiagnostics;
use Phalanx\Theatron\Tui\Drawing\Stage;
use Phalanx\Theatron\Tui\Inputs\Binding;
use Phalanx\Theatron\Tui\Inputs\BindingRegistry;
use Phalanx\Theatron\Tui\Inputs\FocusManager;
use Phalanx\Theatron\Tui\Inputs\InputEvent;
use Phalanx\Theatron\Tui\Inputs\InputMode;
use Phalanx\Theatron\Tui\Inputs\InputModeSlice;
use Phalanx\Theatron\Tui\Inputs\KeyEvent;
use Phalanx\Theatron\Tui\Inputs\ModeDispatcher;
use Phalanx\Theatron\Tui\Inputs\NormalModeHandler;
use Phalanx\Theatron\Tui\Kit\ScreenLayout;
use Phalanx\Theatron\Tui\Navigation\Navigator;
use Phalanx\Theatron\Tui\Navigation\OverlayFrame;
use Phalanx\Theatron\Tui\Navigation\OverlayPainter;
use Phalanx\Theatron\Tui\Navigation\WorkspaceNavigator;
use Phalanx\Theatron\Tui\Reactive\SignalRegistry;
use Phalanx\Theatron\Tui\Reactive\Store;
use Phalanx\Theatron\Tui\Styles\Theme;
use Phalanx\Theatron\Tui\Tdom\Painter\PaintContext;
use Phalanx\Theatron\Tui\Tdom\Painter\Painter;
use Phalanx\Theatron\Tui\Tdom\Renderable;

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
                            /** @var class-string<\Phalanx\Theatron\Tui\Core\Component> $target */
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
                $scope->delay(Mark::ms(100));
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
