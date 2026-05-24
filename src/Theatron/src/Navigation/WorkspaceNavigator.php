<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Navigation;

use Phalanx\Theatron\Component\MountedComponent;
use Phalanx\Theatron\Component\MountedScreen;
use Phalanx\Theatron\Component\MountSystem;
use Phalanx\Theatron\Contract\Screen;

final class WorkspaceNavigator implements Navigator
{
    /** @var array<class-string<Screen>, MountedScreen> */
    private array $workspaces = [];

    /** @var list<MountedComponent> */
    private array $overlayStack = [];

    /** @var list<class-string<Screen>> */
    private array $history = [];

    /**
     * @param class-string<Screen> $activeScreen
     */
    public function __construct(
        private(set) MountSystem $mountSystem,
        private string $activeScreen,
    ) {
        $this->workspaces[$this->activeScreen] = $this->mountSystem->mountScreen($this->activeScreen);
    }

    /**
     * @param class-string<Screen> $screen
     */
    public function go(string $screen): void
    {
        if ($this->activeScreen === $screen) {
            return;
        }

        $this->history[] = $this->activeScreen;
        $this->activeScreen = $screen;

        if (!isset($this->workspaces[$screen])) {
            $this->workspaces[$screen] = $this->mountSystem->mountScreen($screen);
        }

        $this->workspaces[$screen]->markDirty();
    }

    public function back(): bool
    {
        $screen = array_pop($this->history);

        if ($screen === null) {
            return false;
        }

        $this->activeScreen = $screen;
        $this->workspaces[$this->activeScreen]->markDirty();

        return true;
    }

    /**
     * @param class-string<\Phalanx\Theatron\Contract\Component> $component
     */
    public function overlay(string $component, mixed ...$params): void
    {
        $mounted = $this->mountSystem->mountComponent($component, ...$params);
        $this->overlayStack[] = $mounted;
        $this->workspaces[$this->activeScreen]->markDirty();
    }

    public function dismiss(): void
    {
        if ($this->overlayStack === []) {
            return;
        }

        $top = array_pop($this->overlayStack);
        $top->dispose();
        $this->workspaces[$this->activeScreen]->markDirty();
    }

    public function dismissAll(): void
    {
        $stack = $this->overlayStack;
        $this->overlayStack = [];

        foreach (array_reverse($stack) as $overlay) {
            $overlay->dispose();
        }

        $this->workspaces[$this->activeScreen]->markDirty();
    }

    public function active(): string
    {
        return $this->activeScreen;
    }

    public function activeWorkspace(): MountedScreen
    {
        return $this->workspaces[$this->activeScreen];
    }

    /** @return list<MountedComponent> */
    public function overlays(): array
    {
        return $this->overlayStack;
    }

    public function hasOverlays(): bool
    {
        return $this->overlayStack !== [];
    }
}
