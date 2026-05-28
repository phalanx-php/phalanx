<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

use Phalanx\Theatron\Store\Slice;

class SettingsSlice implements Slice
{
    public string $key {
        get => 'repl.settings';
    }

    /** @var array<string, bool> */
    private(set) array $toggles = [];

    public function __construct(
        private(set) SettingsTab $activeTab = SettingsTab::General,
        private(set) int $tabScrollOffset = 0,
        private(set) int $selectedItem = 0,
    ) {
    }

    public function selectTab(SettingsTab $tab): self
    {
        $clone = clone $this;
        $clone->activeTab = $tab;
        $clone->selectedItem = 0;

        return $clone;
    }

    public function nextTab(): self
    {
        $tabs = SettingsTab::cases();
        $index = array_search($this->activeTab, $tabs, true);
        $next = ($index + 1) % count($tabs);

        $clone = clone $this;
        $clone->activeTab = $tabs[$next];
        $clone->selectedItem = 0;

        return $clone;
    }

    public function prevTab(): self
    {
        $tabs = SettingsTab::cases();
        $index = array_search($this->activeTab, $tabs, true);
        $prev = ($index - 1 + count($tabs)) % count($tabs);

        $clone = clone $this;
        $clone->activeTab = $tabs[$prev];
        $clone->selectedItem = 0;

        return $clone;
    }

    public function nextItem(int $max): self
    {
        if ($this->selectedItem >= $max - 1) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedItem = $this->selectedItem + 1;

        return $clone;
    }

    public function prevItem(): self
    {
        if ($this->selectedItem <= 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->selectedItem = $this->selectedItem - 1;

        return $clone;
    }

    public function toggleSelected(): self
    {
        $items = $this->activeTab->items();
        $item = $items[$this->selectedItem] ?? null;

        if ($item === null || $item[1] !== 'toggle') {
            return $this;
        }

        $key = $this->activeTab->value . ':' . $this->selectedItem;
        $clone = clone $this;
        $clone->toggles = $this->toggles;
        $clone->toggles[$key] = !$this->isEnabled($this->activeTab, $this->selectedItem);

        return $clone;
    }

    public function isEnabled(SettingsTab $tab, int $index): bool
    {
        $key = $tab->value . ':' . $index;

        return $this->toggles[$key] ?? $tab->items()[$index][2] ?? false;
    }
}
