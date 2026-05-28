<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Demos\Repl\Render\ThinkingOverlay;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

class ThinkingScreen implements Screen
{
    public string $name { get => 'thinking'; }

    public function isOverlay(): bool { return false; }

    public function __construct(
        private(set) ThinkingOverlay $renderer,
    ) {
    }

    /** @return list<HotkeyBinding> */
    public function bindings(): array
    {
        return [];
    }

    public function handleInput(KeyEvent $event, HotkeyContext $ctx): bool
    {
        return false;
    }

    public function render(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable
    {
        return $this->renderer->render($ui, $width, $height);
    }
}
