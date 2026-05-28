<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

use Phalanx\Theatron\Store\Slice;

class ConvoSlice implements Slice
{
    public string $key {
        get => 'repl.convo';
    }

    public function __construct(
        /** @var list<ExchangeSummary> */
        private(set) array $history = [],
        private(set) ?ActiveTurn $activeTurn = null,
        private(set) ?Exchange $lastExchange = null,
        private(set) int $scrollOffset = 0,
        private(set) ?int $expandedIndex = null,
        private(set) bool $showThinking = true,
    ) {
    }

    public function beginTurn(string $userMessage): self
    {
        $clone = clone $this;
        $clone->activeTurn = new ActiveTurn(userMessage: $userMessage);
        $clone->scrollOffset = 0;
        $clone->expandedIndex = null;

        return $clone;
    }

    public function withActiveTurn(ActiveTurn $turn): self
    {
        $clone = clone $this;
        $clone->activeTurn = $turn;

        return $clone;
    }

    public function completeTurn(ExchangeSummary $summary, Exchange $exchange): self
    {
        $clone = clone $this;
        $clone->history = [...$this->history, $summary];
        $clone->lastExchange = $exchange;
        $clone->activeTurn = null;

        return $clone;
    }

    public function withLoadedExchange(?Exchange $exchange): self
    {
        $clone = clone $this;
        $clone->lastExchange = $exchange;

        return $clone;
    }

    public function scrollUp(): self
    {
        if ($this->scrollOffset >= count($this->history)) {
            return $this;
        }

        $clone = clone $this;
        $clone->scrollOffset = $this->scrollOffset + 1;
        $clone->expandedIndex = null;

        return $clone;
    }

    public function scrollDown(): self
    {
        if ($this->scrollOffset <= 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->scrollOffset = $this->scrollOffset - 1;
        $clone->expandedIndex = null;

        return $clone;
    }

    public function refocus(): self
    {
        $clone = clone $this;
        $clone->scrollOffset = 0;
        $clone->expandedIndex = null;

        return $clone;
    }

    public function expandAtScroll(): self
    {
        if ($this->scrollOffset === 0 || $this->history === []) {
            return $this;
        }

        $index = count($this->history) - $this->scrollOffset;

        if ($index < 0 || $index >= count($this->history)) {
            return $this;
        }

        $clone = clone $this;
        $clone->expandedIndex = $index;

        return $clone;
    }

    public function toggleThinking(): self
    {
        $clone = clone $this;
        $clone->showThinking = !$this->showThinking;

        return $clone;
    }

    public function toggleToolExpand(int $toolIndex): self
    {
        if ($this->lastExchange === null) {
            return $this;
        }

        if (!isset($this->lastExchange->toolCalls[$toolIndex])) {
            return $this;
        }

        $call = $this->lastExchange->toolCalls[$toolIndex];
        $updatedCalls = $this->lastExchange->toolCalls;
        $updatedCalls[$toolIndex] = $call->withExpanded(!$call->expanded);

        $clone = clone $this;
        $clone->lastExchange = $this->lastExchange->withToolCalls($updatedCalls);

        return $clone;
    }
}
