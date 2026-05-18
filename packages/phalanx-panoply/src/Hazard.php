<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Discrete risk rating assigned to an Effect by Hazard\Scorer. Stable
 * across runs (no floating-point arithmetic, no host nondeterminism).
 * Grants carry a hazardCeiling: an Effect is authorized only if its
 * Hazard does not exceed the ceiling.
 */
enum Hazard: string
{
    case None = 'none';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    /**
     * Total order for ceiling comparisons. None < Low < Medium < High < Critical.
     */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function exceeds(self $ceiling): bool
    {
        return $this->rank() > $ceiling->rank();
    }
}
