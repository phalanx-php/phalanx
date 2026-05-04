<?php

declare(strict_types=1);

namespace Phalanx\Scope;

/**
 * Cancellable handle returned by scope primitives that produce ongoing
 * background work — periodic timers, signal listeners, stream observers.
 *
 * Cancellation is idempotent. Implementations also cancel automatically
 * when the owning scope is disposed, so callers don't have to remember
 * to clean up; cancel() exists for cases where the caller wants to stop
 * the work earlier than scope teardown.
 */
interface Subscription
{
    public bool $cancelled { get; }

    public function cancel(): void;
}
