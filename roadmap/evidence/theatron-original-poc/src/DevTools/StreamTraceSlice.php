<?php

declare(strict_types=1);

namespace Phalanx\Theatron\DevTools;

use Phalanx\Theatron\Store\Slice;

final class StreamTraceSlice implements Slice
{
    public string $key {
        get => 'theatron.runtime.stream_trace';
    }

    private(set) int $capacity;

    /** @param list<StreamTraceEntry> $entries */
    public function __construct(
        private(set) array $entries = [],
        int $capacity = 50,
    ) {
        $this->capacity = $capacity;
    }

    public function push(StreamTraceEntry $entry): self
    {
        $entries = $this->entries;
        $entries[] = $entry;

        if (count($entries) > $this->capacity) {
            $entries = array_slice($entries, -$this->capacity);
        }

        return new self($entries, $this->capacity);
    }

    public function latest(): ?StreamTraceEntry
    {
        $count = count($this->entries);

        if ($count === 0) {
            return null;
        }

        return $this->entries[$count - 1];
    }
}
