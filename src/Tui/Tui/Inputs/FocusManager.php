<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tui\Inputs;

use Closure;
use Phalanx\Tui\Tui\Core\AcceptsInput;
use Phalanx\Tui\Tui\Core\Focusable;
use Phalanx\Tui\Tui\Inputs\KeyEvent;

class FocusManager
{
    public int $count {
        get {
            return $this->itemCount;
        }
    }

    /** @var list<array{string, Focusable}> */
    private array $items = [];

    private int $itemCount = 0;

    private int $activeIndex = 0;

    /** @var ?Closure(string): void */
    private ?Closure $onFocusChanged = null;

    /** @param Closure(string): void $callback */
    public function onFocusChanged(Closure $callback): void
    {
        $this->onFocusChanged = $callback;
    }

    public function register(string $name, Focusable $focusable): void
    {
        $this->items[] = [$name, $focusable];
        $this->itemCount = \count($this->items);
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

    public function dispatch(KeyEvent $event): bool
    {
        $active = $this->active();

        if (!$active instanceof AcceptsInput) {
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

        $this->activeIndex = ($this->activeIndex + 1) % $this->itemCount;
        $this->notifyFocusChanged();
    }

    public function previous(): void
    {
        if ($this->items === []) {
            return;
        }

        $this->activeIndex = ($this->activeIndex - 1 + $this->itemCount) % $this->itemCount;
        $this->notifyFocusChanged();
    }

    public function reset(): void
    {
        $this->items = [];
        $this->itemCount = 0;
        $this->activeIndex = 0;
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
