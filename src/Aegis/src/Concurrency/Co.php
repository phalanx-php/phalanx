<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Phalanx\Cancellation\Cancelled;
use Phalanx\Runtime\Swoole\SwooleRuntime;

/**
 * Coroutine helpers that translate the engine's "cancellation = return-false +
 * isCanceled() flag" into the exception-based model aegis users expect.
 *
 * 2026-04-30 finding: Coroutine::cancel does NOT raise an exception in the
 * target coroutine. It interrupts the suspended call (usleep returns false,
 * channel pop returns false, ...) and sets isCanceled() to true. The coroutine
 * resumes normally and is free to keep running. To preserve aegis semantics
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
        $ok = SwooleRuntime::usleep((int) round($seconds * 1_000_000));
        if ($ok === false || SwooleRuntime::isCanceled()) {
            throw new Cancelled('sleep interrupted by cancellation');
        }
    }

    public static function throwIfCancelled(): void
    {
        if (SwooleRuntime::isCanceled()) {
            throw new Cancelled('coroutine cancelled');
        }
    }
}
