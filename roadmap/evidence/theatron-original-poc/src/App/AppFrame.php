<?php

declare(strict_types=1);

namespace Phalanx\Theatron\App;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\StatefulComponent;
use Phalanx\Theatron\Kit\FrameLoop;
use Phalanx\Theatron\Kit\ScreenLayout;
use Phalanx\Theatron\Kit\StatusBar;
use Phalanx\Theatron\Region\Region;
use Phalanx\Theatron\Stage\Stage;
use Phalanx\Theatron\Stage\StageConfig;
use Phalanx\Theatron\Store\Lens;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

final class AppFrame
{
    private(set) Stage $stage;
    private(set) ScreenLayout $layout;
    private(set) FrameLoop $loop;
    private(set) AppMount $mount;
    private Ui $ui;

    /** @var list<MountedComponent> */
    private array $auxiliaryMounts = [];

    /** @var array<string, MountedComponent> */
    private array $regionMounts = [];

    /** @var ?(static Closure(FrameLoop, Ui): StatusBar) */
    private ?\Closure $statusBarFactory = null;

    public function __construct(
        StatefulComponent $root,
        ScreenLayout $layout,
        StageConfig $stageConfig,
        ?ExecutionScope $scope = null,
        ?Lens $lens = null,
        ?int $maxFrames = null,
        string $focusName = 'root',
    ) {
        $this->ui = new Ui();
        $this->loop = new FrameLoop();

        $handleInput = $maxFrames === null;
        $config = new StageConfig(
            screenMode: $stageConfig->screenMode,
            mouseTracking: $stageConfig->mouseTracking,
            bracketedPaste: $stageConfig->bracketedPaste,
            syncOutput: $stageConfig->syncOutput,
            handleInput: $handleInput,
            defaultExitHandler: $stageConfig->defaultExitHandler,
            colorMode: $stageConfig->colorMode,
            activeIntervalUs: $stageConfig->activeIntervalUs,
            idleIntervalUs: $stageConfig->idleIntervalUs,
            stream: $stageConfig->stream,
            captureFile: $stageConfig->captureFile,
            fullSgr: $stageConfig->fullSgr,
            flushMemoryCaches: $stageConfig->flushMemoryCaches,
        );

        $this->stage = Stage::boot($config);
        $this->layout = $layout;
        $this->layout->attach($this->stage);

        $this->mount = new AppMount($root, $scope, $lens, $focusName);
        $this->mount->wireInput($this->stage);
    }

    /** @param static \Closure(FrameLoop, Ui): StatusBar $factory */
    public function statusBar(\Closure $factory): self
    {
        $this->statusBarFactory = $factory;

        return $this;
    }

    public function auxiliary(string $regionName, MountedComponent $mount): self
    {
        $this->auxiliaryMounts[] = $mount;
        $this->regionMounts[$regionName] = $mount;

        return $this;
    }

    /** @param static \Closure(Stage): void $callback */
    public function onDraw(\Closure $callback): self
    {
        $this->stage->onDraw($callback);

        return $this;
    }

    public function run(ExecutionScope $scope, ?int $maxFrames = null): void
    {
        $this->wireDrawLoop();

        try {
            if ($maxFrames !== null) {
                $this->runBounded($scope, $maxFrames);
            } else {
                $this->stage->run($scope);
            }
        } finally {
            $this->mount->dispose();

            foreach ($this->auxiliaryMounts as $aux) {
                $aux->dispose();
            }
        }
    }

    private function wireDrawLoop(): void
    {
        $loop = $this->loop;
        $mount = $this->mount;
        $layout = $this->layout;
        $ui = $this->ui;
        $statusBarFactory = $this->statusBarFactory;
        $regionMounts = $this->regionMounts;

        $this->stage->onDraw(static function () use (
            $loop,
            $mount,
            $layout,
            $ui,
            $statusBarFactory,
            $regionMounts,
        ): void {
            $loop->tick();

            if ($loop->shouldDraw($mount->root->consumeDirty())) {
                $layout->region('main')->draw($mount->root->render());
            }

            foreach ($regionMounts as $regionName => $auxMount) {
                if ($loop->needsDraw || $auxMount->consumeDirty()) {
                    $layout->region($regionName)->draw($auxMount->render());
                }
            }

            if ($statusBarFactory !== null && isset($layout->slots['status'])) {
                $bar = $statusBarFactory($loop, $ui);
                $layout->region('status')->draw($bar->render($ui));
            }
        });
    }

    private function runBounded(ExecutionScope $scope, int $maxFrames): void
    {
        $this->stage->start($scope);

        while ($this->loop->frames < $maxFrames) {
            $scope->delay(0.01);
        }

        $this->stage->stop();
    }
}
