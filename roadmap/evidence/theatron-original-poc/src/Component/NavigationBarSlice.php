<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Component;

use Phalanx\Theatron\Store\Slice;

final class NavigationBarSlice implements Slice
{
    public string $key { get => 'theatron.navigation.bar'; }

    /**
     * @param list<NavigationItem> $items
     */
    public function __construct(
        private(set) array $items = [],
        private(set) int $activeIndex = 0,
    ) {
    }

    public function withActive(string $focusName): self
    {
        foreach ($this->items as $i => $item) {
            if ($item->focusName === $focusName) {
                return new self($this->items, $i);
            }
        }

        return $this;
    }
}
