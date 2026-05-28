<?php

declare(strict_types=1);

namespace Phalanx\Theatron;

use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Exception\ServiceNotFoundException;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\DevTools\AegisRuntimeStoreProjector;
use Phalanx\Theatron\DevTools\ComponentTreeProjector;
use Phalanx\Theatron\DevTools\ComponentTreeSlice;
use Phalanx\Theatron\DevTools\DevToolsConfig;
use Phalanx\Theatron\DevTools\DevToolsMode;
use Phalanx\Theatron\DevTools\DevToolsModeSlice;
use Phalanx\Theatron\DevTools\DevToolsOverlay;
use Phalanx\Theatron\DevTools\DevToolsPanel;
use Phalanx\Theatron\DevTools\DockPosition;
use Phalanx\Theatron\DevTools\ReactorStateSlice;
use Phalanx\Theatron\DevTools\RuntimeMemorySlice;
use Phalanx\Theatron\DevTools\RuntimeMetricsSlice;
use Phalanx\Theatron\DevTools\RuntimeScopeSlice;
use Phalanx\Theatron\DevTools\SignalRegistry;
use Phalanx\Theatron\DevTools\SignalRegistrySlice;
use Phalanx\Theatron\DevTools\StreamTraceEntry;
use Phalanx\Theatron\DevTools\StreamTraceSlice;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Reactor\BackgroundReactor;
use Phalanx\Theatron\Reactor\ReactorContext;
use Phalanx\Theatron\Reactor\ReactorGroup;
use Phalanx\Theatron\Reactor\StreamReactor;
use Phalanx\Theatron\Region\RegionConfig;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Slice;
use Phalanx\Theatron\Store\Store;
use Phalanx\Theatron\Store\StoreDefinition;
use Phalanx\Theatron\Store\StoreRegistry;
use Phalanx\Theatron\Stream\TheatronStream;

final class TheatronApp
{
    private(set) ReactorGroup $reactorGroup;

    /**
     * @param list<StoreDefinition> $stores
     * @param list<ServiceBundle> $providers
     * @param list<StreamReactor> $streamReactors
     * @param list<BackgroundReactor> $backgroundReactors
     * @param list<Slice> $initialSlices
     */
    public function __construct(
        private(set) StatefulComponent $root,
        private(set) AppContext $context,
        private(set) array $stores,
        private(set) array $providers = [],
        private(set) array $streamReactors = [],
        private(set) array $backgroundReactors = [],
        private(set) array $initialSlices = [],
        private(set) ?DevToolsConfig $devTools = null,
    ) {
        $this->reactorGroup = new ReactorGroup();
    }

    public function services(): TheatronServiceBundle
    {
        return new TheatronServiceBundle($this);
    }

    public function host(): Application
    {
        return Application::starting($this->context->values)
            ->providers($this->services(), ...$this->providers)
            ->compile();
    }

    public function createRegistry(): StoreRegistry
    {
        $stores = $this->stores;

        if ($this->devTools !== null) {
            $stores[] = Store::concurrent(
                'theatron-devtools',
                RuntimeMetricsSlice::class,
                RuntimeScopeSlice::class,
                RuntimeMemorySlice::class,
                ReactorStateSlice::class,
                StreamTraceSlice::class,
                SignalRegistrySlice::class,
                ComponentTreeSlice::class,
                DevToolsModeSlice::class,
            );
        }

        return StoreRegistry::fromDefinitions(...$stores);
    }

    public function mount(ExecutionScope $scope, ?StoreRegistry $registry = null): MountedComponent
    {
        if ($registry === null) {
            $registry = $this->registryFor($scope);
        } else {
            $registry->start($scope);
        }

        return new MountedComponent($this->root, $scope, $registry->lens());
    }

    public function run(
        ExecutionScope $scope,
        StageConfig $stageConfig = new StageConfig(),
    ): void {
        $registry = $this->registryFor($scope);

        $writer = $registry->writer();
        foreach ($this->initialSlices as $slice) {
            $writer->set($slice);
        }

        $lens = $registry->lens();
        $stage = Stage::boot($stageConfig);
        $root = new MountedComponent($this->root, $scope, $lens);
        $devtools = $this->devTools === null ? null : new MountedComponent(new DevToolsPanel(), $scope, $lens);
        $overlay = $this->devTools === null ? null : new MountedComponent(new DevToolsOverlay(), $scope, $lens);
        $projector = $this->devTools === null
            ? null
            : new AegisRuntimeStoreProjector(
                $scope->service(RuntimeContext::class),
                $registry->writer(),
                $this->reactorGroup,
            );
        $treeProjector = $this->devTools === null ? null : new ComponentTreeProjector($registry->writer());

        if ($this->devTools !== null) {
            SignalRegistry::enable();
        }

        if ($treeProjector !== null) {
            $treeProjector->register($root, 'root', 0);
        }

        $w = $stage->width();
        $h = $stage->height();
        $devtoolsHeight = $this->devtoolsHeight($h);
        $rootRegion = $stage->region('root', $this->rootRect($w, $h, $devtoolsHeight));
        $devtoolsRegion = $this->devTools === null
            ? null
            : $stage->region('devtools', $this->devtoolsRect($w, $h, $this->devTools, $devtoolsHeight));
        $overlayRegion = $this->devTools === null
            ? null
            : $stage->region('overlay', Rect::of(0, 0, 0, 0), new RegionConfig(zIndex: 100));

        $app = $this;

        $frames = 0;
        $needsDraw = true;
        $devtoolsNeedsDraw = true;
        $overlayNeedsDraw = false;
        $devtoolsVisible = $devtools !== null;
        $overlayVisible = false;

        $stage->onResize(static function (
            int $width,
            int $height,
        ) use (
            $app,
            $rootRegion,
            $devtoolsRegion,
            $overlayRegion,
            &$devtoolsVisible,
            &$overlayVisible,
            &$needsDraw,
            &$overlayNeedsDraw,
        ): void {
            if ($overlayVisible) {
                $overlayRegion?->resize(Rect::of(0, 0, $width, $height));
                $overlayNeedsDraw = true;
            }

            if ($devtoolsVisible) {
                $devtoolsHeight = $app->devtoolsHeight($height);
                $rootRegion->resize($app->rootRect($width, $height, $devtoolsHeight));

                if ($devtoolsRegion !== null && $app->devTools !== null) {
                    $devtoolsRegion->resize($app->devtoolsRect($width, $height, $app->devTools, $devtoolsHeight));
                }
            } else {
                $rootRegion->resize(Rect::of(0, 0, $width, $height));
            }

            $needsDraw = true;
        });

        if ($this->devTools !== null) {
            $storeWriter = $registry->writer();

            $stage->onInput(static function (InputEvent $event) use (
                $app,
                $stage,
                $rootRegion,
                $devtoolsRegion,
                $overlayRegion,
                $storeWriter,
                &$devtoolsVisible,
                &$overlayVisible,
                &$needsDraw,
                &$devtoolsNeedsDraw,
                &$overlayNeedsDraw,
            ): void {
                if (!$event instanceof KeyEvent) {
                    return;
                }

                if ($event->is(Key::F12)) {
                    $overlayVisible = !$overlayVisible;
                    $w = $stage->width();
                    $h = $stage->height();

                    if ($overlayVisible) {
                        $overlayRegion?->resize(Rect::of(0, 0, $w, $h));
                        $overlayNeedsDraw = true;
                        $storeWriter->set(new DevToolsModeSlice(DevToolsMode::Overlay));
                    } else {
                        $overlayRegion?->resize(Rect::of(0, 0, 0, 0));
                        $storeWriter->set(new DevToolsModeSlice(
                            $devtoolsVisible ? DevToolsMode::Docked : DevToolsMode::Hidden,
                        ));
                    }

                    $needsDraw = true;

                    return;
                }

                if ($event->ctrl && $event->is('d')) {
                    $devtoolsVisible = !$devtoolsVisible;
                    $w = $stage->width();
                    $h = $stage->height();

                    if ($devtoolsVisible) {
                        $dtHeight = $app->devtoolsHeight($h);
                        $rootRegion->resize($app->rootRect($w, $h, $dtHeight));
                        $devtoolsRegion?->resize($app->devtoolsRect($w, $h, $app->devTools, $dtHeight));
                        $devtoolsNeedsDraw = true;
                    } else {
                        $rootRegion->resize(Rect::of(0, 0, $w, $h));
                        $devtoolsRegion?->resize(Rect::of(0, $h, $w, 0));
                    }

                    if (!$overlayVisible) {
                        $storeWriter->set(new DevToolsModeSlice(
                            $devtoolsVisible ? DevToolsMode::Docked : DevToolsMode::Hidden,
                        ));
                    }

                    $needsDraw = true;

                    return;
                }

                if ($overlayVisible && $event->isChar()) {
                    $tabIndex = match ($event->char()) {
                        '1' => 0,
                        '2' => 1,
                        '3' => 2,
                        default => null,
                    };

                    if ($tabIndex !== null) {
                        $storeWriter->update(
                            DevToolsModeSlice::class,
                            static fn(DevToolsModeSlice $s): DevToolsModeSlice => $s,
                        );
                    }
                }
            });
        }

        $streamDirty = new \Phalanx\Theatron\Reactive\DirtyBatch();
        $stream = new TheatronStream($streamDirty);

        if ($this->devTools !== null) {
            $traceWriter = $registry->writer();
            $stream->onTrace(static function (string $eventClass, int $subscriberCount) use ($traceWriter): void {
                $traceWriter->update(
                    StreamTraceSlice::class,
                    static fn(StreamTraceSlice $s): StreamTraceSlice => $s->push(
                        new StreamTraceEntry($eventClass, microtime(true), $subscriberCount),
                    ),
                );
            });
        }

        $stream->start($scope);

        $reactorContext = new ReactorContext(
            scope: $scope,
            lens: $lens,
            writer: $registry->writer(),
            dirty: $streamDirty,
        );

        foreach ($this->streamReactors as $reactor) {
            $reactor->subscribe($stream, $reactorContext);
        }

        foreach ($this->backgroundReactors as $reactor) {
            $this->reactorGroup->register($reactor);
            $reactor->start($scope, $stream);
        }

        $signalWriter = $this->devTools !== null ? $registry->writer() : null;

        $stage->onDraw(static function () use (
            $scope,
            $root,
            $projector,
            $treeProjector,
            $rootRegion,
            $devtools,
            $devtoolsRegion,
            $overlay,
            $overlayRegion,
            $streamDirty,
            $signalWriter,
            &$frames,
            &$needsDraw,
            &$devtoolsNeedsDraw,
            &$devtoolsVisible,
            &$overlayNeedsDraw,
            &$overlayVisible,
        ): void {
            $frames++;

            if ($devtoolsVisible || $overlayVisible) {
                $projector?->project($scope, $frames);
                $treeProjector?->project();

                if ($signalWriter !== null) {
                    $signalWriter->update(
                        SignalRegistrySlice::class,
                        static fn(SignalRegistrySlice $s): SignalRegistrySlice => $s->withSignals(
                            SignalRegistry::snapshot(),
                        ),
                    );
                }
            }

            $streamDirtyConsumed = $streamDirty->consume();

            if ($needsDraw || $root->consumeDirty() || $streamDirtyConsumed) {
                $rootRegion->draw($root->render());
                $needsDraw = false;
            }

            $devtoolsDirty = $devtoolsNeedsDraw || ($devtools?->consumeDirty() ?? false);

            if ($devtoolsVisible && $devtools !== null && $devtoolsRegion !== null && $devtoolsDirty) {
                $devtoolsRegion->draw($devtools->render());
                $devtoolsNeedsDraw = false;
            }

            $overlayDirty = $overlayNeedsDraw || ($overlay?->consumeDirty() ?? false);

            if ($overlayVisible && $overlay !== null && $overlayRegion !== null && $overlayDirty) {
                $overlayRegion->draw($overlay->render());
                $overlayNeedsDraw = false;
            }
        });

        try {
            $stage->run($scope);
        } finally {
            $this->reactorGroup->cancelAll();
            $stream->stop();
            SignalRegistry::disable();
            $root->dispose();
            $devtools?->dispose();
            $overlay?->dispose();
        }
    }

    private function registryFor(ExecutionScope $scope): StoreRegistry
    {
        try {
            return $scope->service(StoreRegistry::class);
        } catch (ServiceNotFoundException) {
            $registry = $this->createRegistry();
            $registry->start($scope);

            return $registry;
        }
    }

    private function rootRect(int $width, int $height, int $devtoolsHeight): Rect
    {
        if ($this->devTools === null) {
            return Rect::of(0, 0, $width, $height);
        }

        $rootHeight = max(1, $height - $devtoolsHeight);

        if ($this->devTools->position === DockPosition::Top) {
            return Rect::of(0, $devtoolsHeight, $width, $rootHeight);
        }

        return Rect::of(0, 0, $width, $rootHeight);
    }

    private function devtoolsHeight(int $height): int
    {
        if ($this->devTools === null) {
            return 0;
        }

        return min($this->devTools->height, max(1, $height - 1));
    }

    private function devtoolsRect(int $width, int $height, DevToolsConfig $config, int $panelHeight): Rect
    {
        $y = $config->position === DockPosition::Top ? 0 : max(0, $height - $panelHeight);

        return Rect::of(0, $y, $width, $panelHeight);
    }
}
