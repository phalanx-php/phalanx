<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

enum BackoffStrategy
{
    case None;
    case Linear;
    case Exponential;

    public function delay(int $attempt, float $baseDelay = 1.0): float
    {
        return match ($this) {
            self::None => 0.0,
            self::Linear => $baseDelay * $attempt,
            self::Exponential => $baseDelay * (2 ** ($attempt - 1)),
        };
    }
}
