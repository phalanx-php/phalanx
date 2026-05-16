<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * Runtime metrics for the supervisor's cancellation token pool.
 *
 * @see \Phalanx\Supervisor\Supervisor::poolStats()
 */
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
            'free' => $this->free,
            'misses' => $this->misses,
            'capacity' => $this->capacity,
        ];
    }
}
