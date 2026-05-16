<?php

declare(strict_types=1);

namespace Phalanx\Pool;

final class PoolStats
{
    public function __construct(
        private(set) int $hits,
        private(set) int $misses,
        private(set) int $overflows,
        private(set) int $drops,
        private(set) int $borrowed,
        private(set) int $free,
        private(set) int $capacity,
    ) {
    }

    /** @return array{hits: int, misses: int, overflows: int, drops: int, borrowed: int, free: int, capacity: int} */
    public function toArray(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'overflows' => $this->overflows,
            'drops' => $this->drops,
            'borrowed' => $this->borrowed,
            'free' => $this->free,
            'capacity' => $this->capacity,
        ];
    }
}
