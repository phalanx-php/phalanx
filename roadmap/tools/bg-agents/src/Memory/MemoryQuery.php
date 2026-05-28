<?php

declare(strict_types=1);

namespace BgAgents\Memory;

final readonly class MemoryQuery
{
    /**
     * @param list<string> $tags     additional tags ANDed with the always-present "bg.memory"
     * @param list<string> $topics   substring matches against MemoryRecord::$topic, OR-combined
     */
    public function __construct(
        public array $tags = [],
        public array $topics = [],
        public int $limit = 10,
    ) {}
}
