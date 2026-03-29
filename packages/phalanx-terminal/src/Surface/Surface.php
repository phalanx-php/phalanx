<?php

declare(strict_types=1);

namespace Phalanx\Terminal\Surface;

use Phalanx\Terminal\Buffer\Buffer;
use Phalanx\Terminal\Buffer\Rect;
use Phalanx\Terminal\Input\InputEvent;
use Phalanx\Terminal\Input\ResizeEvent;
use Phalanx\Terminal\Region\Compositor;
use Phalanx\Terminal\Region\Region;
use Phalanx\Terminal\Region\RegionConfig;
use Phalanx\Terminal\Terminal\RawMode;
use Phalanx\Terminal\Terminal\SttyRawMode;
use Phalanx\Terminal\Terminal\Terminal;
use Phalanx\Terminal\Terminal\TerminalConfig;
use Phalanx\Terminal\Writer\AnsiWriter;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;

final class Surface
{
    private Compositor $compositor;
    private AnsiWriter $writer;
    private Buffer $currentBuffer;
    private Buffer $previousBuffer;
    private RawMode $rawMode;
    private ?InputReader $inputReader = null;
    private ?TimerInterface $renderTimer = null;
    private bool $running = false;
    private bool $useAlternateScreen = false;

    /** @var array<string, list<callable(Message, self): void>> */
    private array $messageHandlers = [];

    /** @var list<callable(self): void> */
    private array $drawCallbacks = [];

    public int $width {
        get => $this->config->terminal->width;
    }

    public int $height {
        get => $this->config->terminal->height;
    }

    public bool $isRunning {
        get => $this->running;
    }

    public function __construct(
        public private(set) SurfaceConfig $config,
        ?RawMode $rawMode = null,
        mixed $stdout = null,
        mixed $stdin = null,
    ) {
        $this->compositor = new Compositor();
        $this->writer = new AnsiWriter($config->terminal->colorMode, $stdout);
        $this->currentBuffer = Buffer::empty($config->terminal->width, $config->terminal->height);
        $this->previousBuffer = Buffer::empty($config->terminal->width, $config->terminal->height);
        $this->rawMode = $rawMode ?? new SttyRawMode();

        $dispatch = $this->dispatchInput(...);
        $this->inputReader = new InputReader($dispatch, $stdin);
    }

    public function region(string $name, Rect $area, ?RegionConfig $config = null): Region
    {
        $region = new Region($name, $area, $config ?? new RegionConfig());
        $this->compositor->register($region);

        return $region;
    }

    public function removeRegion(string $name): void
    {
        $this->compositor->remove($name);
    }

    public function getRegion(string $name): ?Region
    {
        return $this->compositor->get($name);
    }

    public function dispatch(Message|InputEvent $message): void
    {
        $class = $message::class;
        $handlers = $this->messageHandlers[$class] ?? [];

        foreach ($handlers as $handler) {
            $handler($message, $this);
        }
    }

    /** @param callable(Message|InputEvent, self): void $handler */
    public function onMessage(string $messageClass, callable $handler): void
    {
        $this->messageHandlers[$messageClass] ??= [];
        $this->messageHandlers[$messageClass][] = $handler;
    }

    /** @param callable(self): void $drawer */
    public function onDraw(callable $drawer): void
    {
        $this->drawCallbacks[] = $drawer;
    }

    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;
        $this->rawMode->enable();

        $this->useAlternateScreen = match ($this->config->mode) {
            ScreenMode::Alternate => true,
            ScreenMode::Inline => false,
            ScreenMode::Detect => $this->config->terminal->isTty,
        };

        if ($this->useAlternateScreen) {
            $this->writer->enterAlternateScreen();
            $this->writer->clearScreen();
        }

        $this->writer->hideCursor();

        if ($this->config->mouseTracking) {
            $this->writer->enableMouseTracking();
        }

        if ($this->config->bracketedPaste) {
            $this->writer->enableBracketedPaste();
        }

        $this->inputReader?->attach();

        if (function_exists('pcntl_signal')) {
            $surface = $this;
            Loop::addSignal(SIGWINCH, static function () use ($surface): void {
                $newConfig = Terminal::detect();
                $surface->handleResize($newConfig);
            });

            Loop::addSignal(SIGINT, static function () use ($surface): void {
                $surface->stop();
                Loop::stop();
            });

            Loop::addSignal(SIGTERM, static function () use ($surface): void {
                $surface->stop();
                Loop::stop();
            });
        }

        register_shutdown_function($this->emergencyCleanup(...));

        $fps = max($this->config->contentFps, $this->config->structureFps);
        $interval = 1.0 / $fps;

        $this->renderTimer = Loop::addPeriodicTimer($interval, $this->renderTick(...));

        $this->invalidateAll();
    }

    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;

        if ($this->renderTimer !== null) {
            Loop::cancelTimer($this->renderTimer);
            $this->renderTimer = null;
        }

        $this->inputReader?->detach();

        if ($this->config->mouseTracking) {
            $this->writer->disableMouseTracking();
        }

        if ($this->config->bracketedPaste) {
            $this->writer->disableBracketedPaste();
        }

        $this->writer->showCursor();

        if ($this->useAlternateScreen) {
            $this->writer->leaveAlternateScreen();
        }

        $this->rawMode->disable();
    }

    public function invalidateAll(): void
    {
        $this->previousBuffer->clear();
    }

    public function terminalConfig(): TerminalConfig
    {
        return $this->config->terminal;
    }

    /** @param callable(int, int): void $fn receives (width, height) */
    public function onResize(callable $fn): void
    {
        $this->onMessage(ResizeEvent::class, static function (Message|InputEvent $msg) use ($fn): void {
            if ($msg instanceof ResizeEvent) {
                $fn($msg->width, $msg->height);
            }
        });
    }

    public function handleResize(TerminalConfig $newConfig): void
    {
        $this->config = $this->config->withTerminal($newConfig);

        $this->currentBuffer = Buffer::empty($newConfig->width, $newConfig->height);
        $this->previousBuffer = Buffer::empty($newConfig->width, $newConfig->height);
        $this->writer->resetState();

        if ($this->useAlternateScreen) {
            $this->writer->clearScreen();
        }

        $this->dispatch(new ResizeEvent($newConfig->width, $newConfig->height));
    }

    private function dispatchInput(InputEvent $event): void
    {
        if ($event instanceof ResizeEvent) {
            $this->handleResize(
                $this->config->terminal->withSize($event->width, $event->height)
            );
            return;
        }

        $class = $event::class;
        $handlers = $this->messageHandlers[$class] ?? [];

        foreach ($handlers as $handler) {
            $handler($event, $this);
        }
    }

    private function renderTick(): void
    {
        if (!$this->compositor->isDirty) {
            return;
        }

        foreach ($this->drawCallbacks as $drawer) {
            try {
                $drawer($this);
            } catch (\Throwable $e) {
                $this->dispatch(new RenderError('draw-callback', $e));
            }
        }

        $now = microtime(true);
        $this->compositor->compose($this->currentBuffer, $now);

        $updates = $this->currentBuffer->diff($this->previousBuffer);

        if ($updates !== []) {
            $this->writer->flush($updates);

            // Sync previous buffer to match current.
            // Cannot use swap() — the compositor only blits dirty regions into
            // currentBuffer. If we swapped, the "new current" would be stale
            // (missing non-dirty regions), causing every non-dirty cell to
            // diff as changed on the next frame → full redraw every tick → flicker.
            foreach ($updates as $u) {
                $this->previousBuffer->set($u->x, $u->y, $u->char, $u->style);
            }
        }
    }

    private function emergencyCleanup(): void
    {
        if ($this->running) {
            $this->writer->showCursor();

            if ($this->useAlternateScreen) {
                $this->writer->leaveAlternateScreen();
            }

            $this->rawMode->disable();
            $this->running = false;
        }
    }
}
