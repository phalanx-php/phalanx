<?php

declare(strict_types=1);

namespace Phalanx\AiProviders;

/**
 * Minimal supervision surface exposed to {@see Provider} and
 * {@see Transport} implementations. Implementations bind cancellation,
 * timeouts, and diagnostics to the underlying Runtime scope without leaking
 * the full execution-scope surface into the ai-providers contract layer.
 *
 * All suspension inside a Provider or Transport must go through
 * {@see self::call()} so that cancellation and wait reasons stay visible to
 * the framework.
 */
interface Runtime
{
    /**
     * Run `$work` under runtime supervision. Implementations bind
     * cancellation, timeouts, and diagnostics.
     */
    public function call(\Closure $work, ?string $waitReason = null): mixed;

    public function isCancelled(): bool;

    public function throwIfCancelled(): void;

    /**
     * Register a cleanup closure invoked when the runtime scope is cancelled
     * or disposed. Cleanups run in LIFO order.
     */
    public function onCancel(\Closure $cleanup): void;
}
