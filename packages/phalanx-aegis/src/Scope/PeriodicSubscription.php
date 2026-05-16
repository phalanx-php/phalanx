<?php

declare(strict_types=1);

namespace Phalanx\Scope;

use OpenSwoole\Timer;

/**
 * Concrete Subscription returned by TaskExecutor::periodic. Owns one
 * OpenSwoole timer id and clears it on cancel(). Cancellation is
 * idempotent and safe to call after the scope has already disposed
 * (Timer::clear on a stale id is a no-op).
 */
final class PeriodicSubscription implements Subscription
{
    private(set) bool $cancelled = false;

    public function __construct(
        private readonly int $timerId,
    ) {
    }

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }
        $this->cancelled = true;
        Timer::clear($this->timerId);
    }
}
