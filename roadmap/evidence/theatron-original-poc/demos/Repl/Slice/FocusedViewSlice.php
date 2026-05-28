<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

use Phalanx\Theatron\Store\Slice;

class FocusedViewSlice implements Slice
{
    public string $key {
        get => 'repl.focused';
    }

    public function __construct(
        private(set) int $scrollPosition = 0,
        private(set) ?string $searchQuery = null,
        private(set) int $searchMatchIndex = 0,
        private(set) int $totalMatches = 0,
        private(set) FocusedPane $activePane = FocusedPane::Answer,
        private(set) bool $codeBlocksExpanded = false,
    ) {
    }

    public function search(string $query): self
    {
        $clone = clone $this;
        $clone->searchQuery = $query;
        $clone->searchMatchIndex = 0;
        $clone->totalMatches = 0;

        return $clone;
    }

    public function withMatchCount(int $total): self
    {
        $clone = clone $this;
        $clone->totalMatches = $total;

        return $clone;
    }

    public function nextMatch(): self
    {
        if ($this->totalMatches === 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->searchMatchIndex = ($this->searchMatchIndex + 1) % $this->totalMatches;

        return $clone;
    }

    public function prevMatch(): self
    {
        if ($this->totalMatches === 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->searchMatchIndex = ($this->searchMatchIndex - 1 + $this->totalMatches) % $this->totalMatches;

        return $clone;
    }

    public function clearSearch(): self
    {
        $clone = clone $this;
        $clone->searchQuery = null;
        $clone->searchMatchIndex = 0;
        $clone->totalMatches = 0;

        return $clone;
    }

    public function scrollTo(int $position): self
    {
        $clone = clone $this;
        $clone->scrollPosition = max(0, $position);

        return $clone;
    }

    public function switchPane(FocusedPane $pane): self
    {
        $clone = clone $this;
        $clone->activePane = $pane;
        $clone->scrollPosition = 0;

        return $clone;
    }

    public function toggleCodeBlocks(): self
    {
        $clone = clone $this;
        $clone->codeBlocksExpanded = !$this->codeBlocksExpanded;

        return $clone;
    }

    public function reset(): self
    {
        return new self();
    }
}
