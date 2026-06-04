<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

use Phalanx\Mark\Mark;

final class Backoff
{
    private function __construct(
        private(set) BackoffStrategy $strategy,
        private(set) Mark $base,
        private(set) ?Mark $max,
        private(set) Jitter $jitter,
    ) {
    }

    public static function fixed(Mark $delay): self
    {
        return new self(BackoffStrategy::Fixed, $delay, null, Jitter::none());
    }

    public static function linear(Mark $base, ?Mark $max = null): self
    {
        return new self(BackoffStrategy::Linear, $base, $max, Jitter::none());
    }

    public static function exponential(Mark $base, ?Mark $max = null): self
    {
        return new self(BackoffStrategy::Exponential, $base, $max, Jitter::none());
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
            BackoffStrategy::Exponential => $this->base->nanoseconds * (2 ** $attempt),
            BackoffStrategy::Linear => $this->base->nanoseconds * ($attempt + 1),
            BackoffStrategy::Fixed => $this->base->nanoseconds,
        };

        if ($this->max !== null) {
            $baseNs = min($baseNs, $this->max->nanoseconds);
        }

        // PHP promotes int overflow to float; clamp to max or PHP_INT_MAX
        if (is_float($baseNs) || $baseNs < 0) {
            $baseNs = $this->max !== null ? $this->max->nanoseconds : PHP_INT_MAX;
        }

        return $this->jitter->apply(Mark::ns((int) $baseNs));
    }
}
