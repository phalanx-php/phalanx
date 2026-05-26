<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

class SettingsSlice
{
    /**
     * @param array<string, bool> $toggles
     */
    public function __construct(
        private(set) SettingsTab $activeTab = SettingsTab::General,
        private(set) int $selectedItem = 0,
        private(set) array $toggles = [],
    ) {
    }

    public function nextTab(): self
    {
        $tabs = SettingsTab::cases();
        $index = array_search($this->activeTab, $tabs, true);
        $next = ($index + 1) % count($tabs);

        return new self($tabs[$next], 0, $this->toggles);
    }

    public function prevTab(): self
    {
        $tabs = SettingsTab::cases();
        $index = array_search($this->activeTab, $tabs, true);
        $prev = ($index - 1 + count($tabs)) % count($tabs);

        return new self($tabs[$prev], 0, $this->toggles);
    }

    public function nextItem(?string $modelName = null): self
    {
        $max = count($this->activeTab->items($modelName));

        return new self($this->activeTab, min($max - 1, $this->selectedItem + 1), $this->toggles);
    }

    public function prevItem(): self
    {
        return new self($this->activeTab, max(0, $this->selectedItem - 1), $this->toggles);
    }

    public function toggleSelected(?string $modelName = null): self
    {
        $item = $this->activeTab->items($modelName)[$this->selectedItem] ?? null;

        if ($item === null || $item[1] !== 'toggle') {
            return $this;
        }

        $key = $this->key($this->activeTab, $this->selectedItem);
        $toggles = $this->toggles;
        $toggles[$key] = !$this->isEnabled($this->activeTab, $this->selectedItem, $modelName);

        return new self($this->activeTab, $this->selectedItem, $toggles);
    }

    public function isEnabled(SettingsTab $tab, int $index, ?string $modelName = null): bool
    {
        $key = $this->key($tab, $index);

        return $this->toggles[$key] ?? $tab->items($modelName)[$index][2] ?? false;
    }

    private function key(SettingsTab $tab, int $index): string
    {
        return $tab->value . ':' . $index;
    }
}
