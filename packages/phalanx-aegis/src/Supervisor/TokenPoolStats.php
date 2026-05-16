<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

final class TokenPoolStats
{
    public function __construct(
        private(set) int $hits,
        private(set) int $misses,
        private(set) int $free,
        private(set) int $capacity,
    ) {
    }

    /** @return array{hits: int, misses: int, free: int, capacity: int} */
    public function toArray(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'free' => $this->free,
            'capacity' => $this->capacity,
        ];
    }
}
