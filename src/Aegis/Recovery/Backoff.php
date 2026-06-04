<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Closure;
use Phalanx\Mark\Mark;

final class Backoff
{
    private function __construct(
        private(set) string $strategy,
        private(set) Mark $base,
        private(set) ?Mark $max,
        private(set) Jitter $jitter,
    ) {
    }

    public static function fixed(Mark $delay, ?Jitter $jitter = null): self
    {
        return new self('fixed', $delay, null, $jitter ?? Jitter::none());
    }

    public static function linear(Mark $base, ?Mark $max = null, ?Jitter $jitter = null): self
    {
        return new self('linear', $base, $max, $jitter ?? Jitter::none());
    }

    public static function exponential(Mark $base, ?Mark $max = null, ?Jitter $jitter = null): self
    {
        return new self('exponential', $base, $max, $jitter ?? Jitter::none());
    }

    public function withJitter(Jitter $jitter): self
    {
        $clone = clone $this;
        $clone->jitter = $jitter;

        return $clone;
    }

    public function delayFor(int $attempt): Mark
    {
        $baseNs = match ($this->strategy) {
            'exponential' => $this->base->nanoseconds * (2 ** $attempt),
            'linear' => $this->base->nanoseconds * ($attempt + 1),
            default => $this->base->nanoseconds,
        };

        if ($this->max !== null) {
            $baseNs = min($baseNs, $this->max->nanoseconds);
        }

        $delay = Mark::ns((int) $baseNs);

        return $this->jitter->apply($delay);
    }
}
