<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Stage;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Console\Input\ConsoleInput;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Subscription;
use Phalanx\Theatron\Buffer\Buffer;
use Phalanx\Theatron\Buffer\Rect;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\Key;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Rendering\Compositor;
use Phalanx\Theatron\Rendering\Region;
use Phalanx\Theatron\Rendering\RegionConfig;
use Phalanx\Theatron\Tdom\Painter\Painter;
use Phalanx\Theatron\Terminal\Terminal;
use Phalanx\Theatron\Terminal\TerminalConfig;
use Phalanx\Theatron\Writer\AnsiWriter;
use Swoole\Process;

final class Stage
{
    private Buffer $current;
    private Buffer $previous;
    private AnsiWriter $writer;
    private Compositor $compositor;
    private TerminalConfig $terminal;
    private ?Subscription $tickSubscription = null;

    private bool $ticking = false;
    private bool $fullRedraw = true;
    private bool $frameRequested = true;
    private bool $running = false;
    private int $frameCount = 0;

    /** @var list<Closure(int, int): void> */
    private array $resizeListeners = [];

    /** @var list<Closure(InputEvent): void> */
    private array $inputListeners = [];

    /** @var list<Closure(self): void> */
    private array $drawListeners = [];

    /** @var list<Closure(): void> */
    private array $frameListeners = [];

    private function __construct(
        private(set) StageConfig $config,
    ) {
        $this->compositor = new Compositor();
        $this->terminal = Terminal::detect($this->config->env);
        $this->current = Buffer::empty($this->terminal->width, $this->terminal->height);
        $this->previous = Buffer::empty($this->terminal->width, $this->terminal->height);
        $this->writer = new AnsiWriter(
            colorMode: $this->config->colorMode,
            syncOutput: $this->config->syncOutput,
            stream: $this->config->stream,
            captureFile: $this->config->captureFile,
            fullSgr: $this->config->fullSgr,
        );
    }

    public static function boot(StageConfig $config = new StageConfig()): self
    {
        return new self($config);
    }

    public function region(string $name, Rect $area, RegionConfig $regionConfig = new RegionConfig()): Region
    {
        $region = new Region($name, $area, $regionConfig);
        $this->compositor->register($region);

        return $region;
    }

    public function requestRedraw(): void
    {
        $this->fullRedraw = true;
        $this->requestFrame();
    }

    public function requestFrame(): void
    {
        $this->frameRequested = true;
    }

    /** @param Closure(int, int): void $listener */
    public function onResize(Closure $listener): void
    {
        $this->resizeListeners[] = $listener;
    }

    /** @param Closure(InputEvent): void $listener */
    public function onInput(Closure $listener): void
    {
        $this->inputListeners[] = $listener;
    }

    /** @param Closure(self): void $listener */
    public function onDraw(Closure $listener): void
    {
        $this->drawListeners[] = $listener;
    }

    /** @param Closure(): void $listener */
    public function onFrame(Closure $listener): void
    {
        $this->frameListeners[] = $listener;
    }

    public function width(): int
    {
        return $this->terminal->width;
    }

    public function height(): int
    {
        return $this->terminal->height;
    }

    public function start(ExecutionScope $scope): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->enterScreen();
        $this->installResizeHandler($scope);
        $this->registerDispose($scope);

        if ($this->config->handleInput) {
            if ($this->config->defaultExitHandler) {
                $this->installDefaultExitHandler($scope);
            }

            $this->startInput($scope);
        }

        $this->startTickLoop($scope);
    }

    public function run(ExecutionScope $scope): void
    {
        $this->start($scope);

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

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        $this->tickSubscription?->cancel();
        $this->tickSubscription = null;
        Process::signal(SIGWINCH, null);
        Painter::reset();
        $this->leaveScreen();
    }

    private function installDefaultExitHandler(ExecutionScope $scope): void
    {
        $this->onInput(static function (InputEvent $event) use ($scope): void {
            if (!$event instanceof KeyEvent) {
                return;
            }

            if ($event->is(Key::Escape) || ($event->ctrl && $event->is('c'))) {
                $scope->cancellation()->cancel();
            }
        });
    }

    private function startInput(ExecutionScope $scope): void
    {
        $consoleInput = $scope->service(ConsoleInput::class);

        if ($consoleInput->isInteractive) {
            $consoleInput->enableRawMode($scope);
        }

        $stage = $this;
        InputFiber::start($scope, $consoleInput, static function (InputEvent $event) use ($stage): void {
            $stage->dispatchInput($event);
        });

        $scope->onDispose(static function () use ($consoleInput, $scope): void {
            if ($consoleInput->isInteractive) {
                $consoleInput->restore($scope);
            }
        });
    }

    private function dispatchInput(InputEvent $event): void
    {
        foreach ($this->inputListeners as $listener) {
            $listener($event);
        }
    }

    private function enterScreen(): void
    {
        if ($this->config->screenMode === ScreenMode::Alternate) {
            $this->writer->enterAlternateScreen();
        }

        $this->writer->hideCursor();
        $this->writer->clearScreen();

        if ($this->config->mouseTracking) {
            $this->writer->enableMouseTracking();
        }

        if ($this->config->bracketedPaste) {
            $this->writer->enableBracketedPaste();
        }
    }

    private function leaveScreen(): void
    {
        if ($this->config->bracketedPaste) {
            $this->writer->disableBracketedPaste();
        }

        if ($this->config->mouseTracking) {
            $this->writer->disableMouseTracking();
        }

        $this->writer->resetState();
        $this->writer->showCursor();

        if ($this->config->screenMode === ScreenMode::Alternate) {
            $this->writer->leaveAlternateScreen();
        }
    }

    private function startTickLoop(ExecutionScope $scope): void
    {
        $intervalSec = $this->config->activeIntervalUs / 1_000_000;

        $stage = $this;
        $this->tickSubscription = $scope->periodic($intervalSec, static function () use ($stage): void {
            $stage->tick();
        });
    }

    private function tick(): void
    {
        if ($this->ticking || !$this->running) {
            return;
        }

        $this->ticking = true;

        try {
            foreach ($this->drawListeners as $listener) {
                $listener($this);
            }

            if (!$this->frameRequested && !$this->fullRedraw && !$this->compositor->isDirty) {
                return;
            }

            $this->current->clear();
            $this->compositor->composeAll($this->current);

            if ($this->fullRedraw) {
                $this->writer->resetState();
                $this->previous = Buffer::empty($this->current->width, $this->current->height);
            }

            $this->writer->renderDiff($this->current, $this->previous);
            $this->current->swap($this->previous);
            $this->fullRedraw = false;
            $this->frameRequested = false;

            $this->frameCount++;

            foreach ($this->frameListeners as $listener) {
                $listener();
            }

            if ($this->config->flushMemoryCaches && $this->frameCount % 60 === 0) {
                gc_mem_caches();
            }
        } finally {
            $this->ticking = false;
        }
    }

    private function installResizeHandler(ExecutionScope $scope): void
    {
        $stage = $this;
        Process::signal(SIGWINCH, static function () use ($stage): void {
            $stage->handleResize();
        });
        $scope->onDispose(static function (): void {
            Process::signal(SIGWINCH, null);
        });
    }

    private function handleResize(): void
    {
        $this->terminal = Terminal::detect($this->config->env);
        $w = $this->terminal->width;
        $h = $this->terminal->height;

        if ($w === $this->current->width && $h === $this->current->height) {
            $this->requestRedraw();

            return;
        }

        $this->current = Buffer::empty($w, $h);
        $this->previous = Buffer::empty($w, $h);

        $this->writer->resetState();
        $this->writer->clearScreen();
        $this->fullRedraw = true;

        foreach ($this->resizeListeners as $listener) {
            $listener($w, $h);
        }
    }

    private function registerDispose(ExecutionScope $scope): void
    {
        $stage = $this;
        $scope->onDispose(static function () use ($stage): void {
            $stage->stop();
        });
    }
}
