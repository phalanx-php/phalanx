<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Focus;

use Closure;
use Phalanx\Theatron\Input\InputEvent;
use Phalanx\Theatron\Input\InputTarget;

final class FocusManager
{
    /** @var list<array{string, Focusable}> */
    private array $items = [];
    private int $activeIndex = 0;

    /** @var ?Closure(string): void */
    private ?Closure $onFocusChanged = null;

    public int $count {
        get => count($this->items);
    }

    /** @param Closure(string): void $callback */
    public function onFocusChanged(Closure $callback): void
    {
        $this->onFocusChanged = $callback;
    }

    public function register(string $name, Focusable $focusable): void
    {
        $this->items[] = [$name, $focusable];
    }

    public function focus(string $name): void
    {
        foreach ($this->items as $i => [$n, $_]) {
            if ($n === $name) {
                $this->activeIndex = $i;
                $this->notifyFocusChanged();

                return;
            }
        }
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn(array $item): string => $item[0], $this->items);
    }

    public function active(): ?Focusable
    {
        return $this->items[$this->activeIndex][1] ?? null;
    }

    public function dispatch(InputEvent $event): bool
    {
        $active = $this->active();

        if (!$active instanceof InputTarget) {
            return false;
        }

        return $active->handleInput($event);
    }

    public function activeName(): ?string
    {
        return $this->items[$this->activeIndex][0] ?? null;
    }

    public function next(): void
    {
        if ($this->items === []) {
            return;
        }

        $this->activeIndex = ($this->activeIndex + 1) % count($this->items);
        $this->notifyFocusChanged();
    }

    public function previous(): void
    {
        if ($this->items === []) {
            return;
        }

        $this->activeIndex = ($this->activeIndex - 1 + count($this->items)) % count($this->items);
        $this->notifyFocusChanged();
    }

    private function notifyFocusChanged(): void
    {
        if ($this->onFocusChanged === null) {
            return;
        }

        $name = $this->activeName();

        if ($name !== null) {
            ($this->onFocusChanged)($name);
        }
    }
}
