<?php

declare(strict_types=1);

namespace Phalanx\Scope;

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

    public static function none(): self
    {
        return new self(BackoffStrategy::Fixed, Mark::zero(), null, Jitter::none());
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
        $maxNs = $this->max?->nanoseconds;

        $baseNs = match ($this->strategy) {
            BackoffStrategy::Exponential => $this->exponentialDelay($attempt, $maxNs),
            BackoffStrategy::Linear => $this->base->nanoseconds * ($attempt + 1),
            BackoffStrategy::Fixed => $this->base->nanoseconds,
        };

        if ($maxNs !== null && $baseNs > $maxNs) {
            $baseNs = $maxNs;
        }

        return $this->jitter->apply(Mark::ns($baseNs));
    }

    private function exponentialDelay(int $attempt, ?int $maxNs): int
    {
        $cap = $maxNs ?? PHP_INT_MAX;

        if ($this->base->nanoseconds === 0) {
            return 0;
        }

        $maxMultiplier = intdiv($cap, $this->base->nanoseconds);
        $multiplier = 2 ** min($attempt, 62);

        if ($multiplier > $maxMultiplier) {
            return $cap;
        }

        return $this->base->nanoseconds * $multiplier;
    }
}
