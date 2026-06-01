<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tui\Inputs;

use Phalanx\Theatron\Tui\Inputs\Key;
use Phalanx\Theatron\Tui\Inputs\KeyEvent;

/**
 * Layered key-binding resolver.
 *
 * Resolution priority (top wins):
 *   overlay stack (most recent first)
 *   → active screen
 *   → global
 *
 * Layers are additive — pushing an overlay does not remove screen or global
 * bindings. The first layer to match the event wins; unmatched events return
 * null so the caller can fall through to normal input handling.
 */
final class BindingRegistry
{
    /** @var list<Binding> */
    private array $global = [];

    /** @var array<class-string, list<Binding>> */
    private array $screenBindings = [];

    /** @var string|null */
    private ?string $activeScreen = null;

    /** @var list<array{id: string, bindings: list<Binding>}> */
    private array $overlayStack = [];

    /** @param list<Binding> $bindings */
    public function setGlobal(array $bindings): void
    {
        $this->global = $bindings;
    }

    /**
     * Register bindings for a named screen class. Calling again for the same
     * screen replaces the existing set.
     *
     * @param class-string $screen
     * @param list<Binding> $bindings
     */
    public function setScreen(string $screen, array $bindings): void
    {
        $this->screenBindings[$screen] = $bindings;
    }

    /**
     * Activate a screen by class name so its bindings participate in resolution.
     *
     * @param class-string $screen
     */
    public function activateScreen(string $screen): void
    {
        $this->activeScreen = $screen;
    }

    /**
     * Push a named overlay layer onto the stack.
     *
     * @param list<Binding> $bindings
     */
    public function pushOverlay(string $id, array $bindings): void
    {
        $this->overlayStack[] = ['id' => $id, 'bindings' => $bindings];
    }

    public function popOverlay(): void
    {
        if ($this->overlayStack !== []) {
            array_pop($this->overlayStack);
        }
    }

    public function clearOverlays(): void
    {
        $this->overlayStack = [];
    }

    public function resolve(KeyEvent $event): ?Binding
    {
        for ($i = count($this->overlayStack) - 1; $i >= 0; $i--) {
            foreach ($this->overlayStack[$i]['bindings'] as $binding) {
                if ($binding->matches($event)) {
                    return $binding;
                }
            }
        }

        if ($this->activeScreen !== null) {
            $screenBindings = $this->screenBindings[$this->activeScreen] ?? [];

            foreach ($screenBindings as $binding) {
                if ($binding->matches($event)) {
                    return $binding;
                }
            }
        }

        foreach ($this->global as $binding) {
            if ($binding->matches($event)) {
                return $binding;
            }
        }

        return null;
    }

    /**
     * Return all bindings visible in the current layer configuration.
     * Useful for rendering a key-hint bar. Higher-priority layers shadow
     * lower-priority ones for the same key combo.
     *
     * @return list<Binding>
     */
    public function activeBindings(): array
    {
        $seen = [];
        $result = [];

        for ($i = count($this->overlayStack) - 1; $i >= 0; $i--) {
            foreach ($this->overlayStack[$i]['bindings'] as $binding) {
                $key = self::comboKey($binding);

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[] = $binding;
                }
            }
        }

        if ($this->activeScreen !== null) {
            foreach ($this->screenBindings[$this->activeScreen] ?? [] as $binding) {
                $key = self::comboKey($binding);

                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $result[] = $binding;
                }
            }
        }

        foreach ($this->global as $binding) {
            $key = self::comboKey($binding);

            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $binding;
            }
        }

        return $result;
    }

    private static function comboKey(Binding $b): string
    {
        $k = $b->key instanceof Key ? $b->key->value : $b->key;

        return ($b->ctrl ? 'c' : '-') . ($b->alt ? 'a' : '-') . ($b->shift ? 's' : '-') . ':' . $k;
    }
}
