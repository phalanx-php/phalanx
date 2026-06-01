<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

use Closure;

final class BindingAction
{
    private function __construct(
        private(set) BindingActionKind $kind,
        private(set) ?string $target = null,
        private(set) ?Closure $callback = null,
    ) {
    }

    public static function quit(): self
    {
        return new self(BindingActionKind::Quit);
    }

    /** @param class-string $screen */
    public static function workspace(string $screen): self
    {
        return new self(BindingActionKind::Workspace, target: $screen);
    }

    /** @param class-string $component */
    public static function toggle(string $component): self
    {
        return new self(BindingActionKind::Toggle, target: $component);
    }

    public static function back(): self
    {
        return new self(BindingActionKind::Back);
    }

    public static function action(Closure $callback): self
    {
        return new self(BindingActionKind::Action, callback: $callback);
    }

    public function isQuit(): bool
    {
        return $this->kind === BindingActionKind::Quit;
    }

    public function isWorkspace(): bool
    {
        return $this->kind === BindingActionKind::Workspace;
    }

    public function isToggle(): bool
    {
        return $this->kind === BindingActionKind::Toggle;
    }

    public function isBack(): bool
    {
        return $this->kind === BindingActionKind::Back;
    }

    public function isAction(): bool
    {
        return $this->kind === BindingActionKind::Action;
    }
}
