<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;

final class Circuit
{
    private function __construct(
        private(set) CircuitKey $key,
        private(set) int $failureThreshold = 5,
        private(set) ?Mark $failureWindow = null,
        private(set) ?Mark $cooldown = null,
        private(set) int $maxProbes = 2,
    ) {
    }

    public static function named(CircuitKey $key): self
    {
        return new self(key: $key);
    }

    public function openAfter(int $failures, Mark $within): self
    {
        $clone = clone $this;
        $clone->failureThreshold = $failures;
        $clone->failureWindow = $within;

        return $clone;
    }

    public function cooldown(Mark $duration): self
    {
        $clone = clone $this;
        $clone->cooldown = $duration;

        return $clone;
    }

    public function halfOpen(int $maxProbes): self
    {
        $clone = clone $this;
        $clone->maxProbes = $maxProbes;

        return $clone;
    }
}
