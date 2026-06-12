<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use Closure;
use Phalanx\Mark\Mark;

final class Jitter
{
    /** @param Closure(): float $randomSource */
    private function __construct(
        private(set) string $mode,
        private(set) int $percent,
        private(set) ?Mark $min,
        private(set) ?Mark $max,
        private(set) Closure $randomSource,
    ) {
    }

    public static function none(): self
    {
        return new self('none', 0, null, null, static fn (): float => 0.0);
    }

    /** @param Closure(): float|null $randomSource */
    public static function percent(int $percent, ?Closure $randomSource = null): self
    {
        return new self(
            'percent',
            $percent,
            null,
            null,
            $randomSource ?? static fn (): float => mt_rand() / mt_getrandmax(),
        );
    }

    /** @param Closure(): float|null $randomSource */
    public static function range(Mark $min, Mark $max, ?Closure $randomSource = null): self
    {
        return new self(
            'range',
            0,
            $min,
            $max,
            $randomSource ?? static fn (): float => mt_rand() / mt_getrandmax(),
        );
    }

    public function apply(Mark $delay): Mark
    {
        return match ($this->mode) {
            'percent' => $this->applyPercent($delay),
            'range' => $this->applyRange($delay),
            default => $delay,
        };
    }

    private function applyPercent(Mark $delay): Mark
    {
        $jitterNs = (int) ($delay->nanoseconds * ($this->percent / 100) * ($this->randomSource)());

        return $delay->plus(Mark::ns($jitterNs));
    }

    private function applyRange(Mark $delay): Mark
    {
        $min = $this->min;
        $max = $this->max;

        if ($min === null || $max === null) {
            return $delay;
        }

        $range = $max->nanoseconds - $min->nanoseconds;
        $jitterNs = $min->nanoseconds + (int) ($range * ($this->randomSource)());

        return $delay->plus(Mark::ns($jitterNs));
    }
}
