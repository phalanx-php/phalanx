<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Navigation;

use Phalanx\Tui\Tui\Core\Component;
use Phalanx\Tui\Tui\Core\Screen;

interface Navigator
{
    /** @param class-string<Screen> $screen */
    public function go(string $screen): void;

    public function back(): bool;

    /** @param class-string<Component> $component */
    public function overlay(string $component, mixed ...$params): void;

    public function dismiss(): void;

    public function dismissAll(): void;

    /** @return class-string<Screen> */
    public function active(): string;
}
