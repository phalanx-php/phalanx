<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Slice;

class DevToolsSlice
{
    public function __construct(
        private(set) DevToolsTab $activeTab = DevToolsTab::Metrics,
    ) {
    }

    public function nextTab(): self
    {
        $tabs = DevToolsTab::cases();
        $index = array_search($this->activeTab, $tabs, true);
        $next = ($index + 1) % count($tabs);

        return new self($tabs[$next]);
    }

    public function prevTab(): self
    {
        $tabs = DevToolsTab::cases();
        $index = array_search($this->activeTab, $tabs, true);
        $prev = ($index - 1 + count($tabs)) % count($tabs);

        return new self($tabs[$prev]);
    }
}
