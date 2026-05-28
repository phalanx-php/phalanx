<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Screen;

use Phalanx\Theatron\Demos\Repl\Input\HotkeyBinding;
use Phalanx\Theatron\Demos\Repl\Input\HotkeyContext;
use Phalanx\Theatron\Input\KeyEvent;
use Phalanx\Theatron\Tdom\Renderable;
use Phalanx\Theatron\Tdom\Ui;

interface Screen
{
    public string $name { get; }

    public function isOverlay(): bool;

    /** @return list<HotkeyBinding> */
    public function bindings(): array;

    public function handleInput(KeyEvent $event, HotkeyContext $ctx): bool;

    public function render(Ui $ui, HotkeyContext $ctx, int $width, int $height): Renderable;
}
