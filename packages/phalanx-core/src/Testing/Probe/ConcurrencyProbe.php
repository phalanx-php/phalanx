<?php

declare(strict_types=1);

namespace Phalanx\Testing\Probe;

final class ConcurrencyProbe
{
    public private(set) int $maxConcurrent = 0;

    public private(set) int $current = 0;

    public function enter(): void
    {
        $this->current++;
        if ($this->current > $this->maxConcurrent) {
            $this->maxConcurrent = $this->current;
        }
    }

    public function exit(): void
    {
        assert($this->current > 0, 'exit() called without matching enter()');
        $this->current--;
    }

    public function reset(): void
    {
        $this->current = 0;
        $this->maxConcurrent = 0;
    }
}
