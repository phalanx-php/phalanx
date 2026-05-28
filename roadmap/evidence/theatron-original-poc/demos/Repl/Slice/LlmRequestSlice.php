<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Demos\Repl\Slice;

use Phalanx\Theatron\Store\Slice;

class LlmRequestSlice implements Slice
{
    private const int MAX_ENTRIES = 50;

    public string $key { get => 'llm-requests'; }

    /** @param list<LlmRequestEntry> $entries */
    public function __construct(
        private(set) array $entries = [],
        private(set) int $focusedIndex = 0,
        private(set) int $detailScrollOffset = 0,
    ) {
    }

    public function append(LlmRequestEntry $entry): self
    {
        $clone = clone $this;
        $clone->entries = [...$this->entries, $entry];

        if (count($clone->entries) > self::MAX_ENTRIES) {
            $clone->entries = array_slice($clone->entries, -self::MAX_ENTRIES);
        }

        $clone->focusedIndex = count($clone->entries) - 1;

        return $clone;
    }

    public function completeById(string $requestId, int $status, float $elapsedMs, int $tokenCount, string $responseBody): self
    {
        $index = $this->findById($requestId);

        if ($index === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->entries[$index] = $clone->entries[$index]->markComplete($status, $elapsedMs, $tokenCount, $responseBody);

        return $clone;
    }

    public function errorById(string $requestId, string $error, float $elapsedMs): self
    {
        $index = $this->findById($requestId);

        if ($index === null) {
            return $this;
        }

        $clone = clone $this;
        $clone->entries[$index] = $clone->entries[$index]->markError($error, $elapsedMs);

        return $clone;
    }

    public function focusUp(): self
    {
        if ($this->focusedIndex <= 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->focusedIndex--;

        return $clone;
    }

    public function focusDown(): self
    {
        if ($this->focusedIndex >= count($this->entries) - 1) {
            return $this;
        }

        $clone = clone $this;
        $clone->focusedIndex++;

        return $clone;
    }

    public function focused(): ?LlmRequestEntry
    {
        return $this->entries[$this->focusedIndex] ?? null;
    }

    public function scrollDetailUp(int $lines = 3): self
    {
        if ($this->detailScrollOffset <= 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->detailScrollOffset = max(0, $this->detailScrollOffset - $lines);

        return $clone;
    }

    public function scrollDetailDown(int $lines = 3): self
    {
        $clone = clone $this;
        $clone->detailScrollOffset += $lines;

        return $clone;
    }

    public function resetDetailScroll(): self
    {
        if ($this->detailScrollOffset === 0) {
            return $this;
        }

        $clone = clone $this;
        $clone->detailScrollOffset = 0;

        return $clone;
    }

    private function findById(string $requestId): ?int
    {
        foreach ($this->entries as $i => $entry) {
            if ($entry->requestId === $requestId) {
                return $i;
            }
        }

        return null;
    }
}
