<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

final class RestartPolicy
{
    public function __construct(
        private(set) int $maxRestarts = 3,
        private(set) float $window = 60.0,
        private(set) BackoffStrategy $backoff = BackoffStrategy::None,
        private(set) OnExhausted $onExhausted = OnExhausted::Stop,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function never(): self
    {
        return new self(maxRestarts: 0);
    }

    public static function aggressive(int $maxRestarts = 10, float $window = 30.0): self
    {
        return new self(
            maxRestarts: $maxRestarts,
            window: $window,
            backoff: BackoffStrategy::Exponential,
        );
    }
}
