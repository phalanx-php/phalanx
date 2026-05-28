<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyRegistry;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;
use Phalanx\Theatron\Text\Line;

final class ScreenStack
{
    /** @var array<string, Screen> */
    private array $screens = [];

    /** @var list<string> */
    private array $stack = [];

    public function __construct(
        private(set) HotkeyRegistry $globals,
    ) {
    }

    public function register(Screen $screen): void
    {
        $this->screens[$screen->name] = $screen;
    }

    public function push(string $name): void
    {
        if (!isset($this->screens[$name])) {
            return;
        }

        if ($this->stack !== [] && $this->stack[array_key_last($this->stack)] === $name) {
            return;
        }

        $this->stack[] = $name;
    }

    public function pop(): bool
    {
        if (count($this->stack) <= 1) {
            return false;
        }

        array_pop($this->stack);

        return true;
    }

    public function top(): ?Screen
    {
        if ($this->stack === []) {
            return null;
        }

        return $this->screens[$this->stack[array_key_last($this->stack)]] ?? null;
    }

    public function topName(): ?string
    {
        return $this->stack !== [] ? $this->stack[array_key_last($this->stack)] : null;
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    public function dispatch(KeyEvent $event, HotkeyContext $ctx): bool
    {
        $screen = $this->top();

        if ($screen === null) {
            return false;
        }

        foreach ($screen->bindings() as $binding) {
            if ($binding->matches($event)) {
                ($binding->handler)($ctx);

                return true;
            }
        }

        return $screen->handleInput($event, $ctx);
    }

    public function isTopOverlay(): bool
    {
        $screen = $this->top();

        return $screen !== null && $screen->isOverlay();
    }

    public function renderBase(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
    {
        if (count($this->stack) < 2) {
            return $ui->text(Line::plain(''));
        }

        $baseName = $this->stack[count($this->stack) - 2];
        $base = $this->screens[$baseName] ?? null;

        if ($base === null) {
            return $ui->text(Line::plain(''));
        }

        return $base->render($ui, $ctx, $width, $height);
    }

    public function renderTop(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
    {
        $screen = $this->top();

        if ($screen === null) {
            return $ui->text(Line::plain(''));
        }

        return $screen->render($ui, $ctx, $width, $height);
    }

    public function render(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
    {
        $screen = $this->top();

        if ($screen === null) {
            return $ui->text(Line::plain('No active screen'));
        }

        return $screen->render($ui, $ctx, $width, $height);
    }
}
