<?php

declare(strict_types=1);

final class DumpStore
{
    private array $entries = [];
    private int $sequence = 0;

    public function __construct(private readonly int $maxEntries = 200) {}

    public function push(array $entry): array
    {
        $entry['id'] = ++$this->sequence;
        $entry['timestamp'] = microtime(true);

        $this->entries[] = $entry;

        if (count($this->entries) > $this->maxEntries) {
            $this->entries = array_slice($this->entries, -$this->maxEntries);
        }

        return $entry;
    }

    public function recent(int $limit = 50): array
    {
        return array_slice($this->entries, -$limit);
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
