<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Phalanx\Cancellation\Cancelled;
use Swoole\Coroutine;

/**
 * Coroutine helpers that translate the engine's "cancellation = return-false +
 * isCanceled() flag" into the exception-based model runtime users expect.
 *
 * 2026-04-30 finding: Coroutine::cancel does NOT raise an exception in the
 * target coroutine. It interrupts the suspended call (usleep returns false,
 * channel pop returns false, ...) and sets isCanceled() to true. The coroutine
 * resumes normally and is free to keep running. To preserve runtime semantics
 * ("cancellation propagates through await/sleep as a thrown exception"), every
 * primitive in this package that suspends checks isCanceled() on resume and
 * throws Cancelled.
 */
final class Co
{
    public static function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            self::throwIfCancelled();

            return;
        }
        // Swoole 6's coroutine timer floor is 1ms; clamp sub-ms waits up so a
        // positive duration never trips the "Timer must be >= 0.001" warning.
        $ok = Coroutine::sleep(max($seconds, 0.001));
        if ($ok === false || Coroutine::isCanceled()) {
            throw new Cancelled('sleep interrupted by cancellation');
        }
    }

    public static function throwIfCancelled(): void
    {
        if (Coroutine::isCanceled()) {
            throw new Cancelled('coroutine cancelled');
        }
    }
}
