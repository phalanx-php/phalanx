<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Phalanx\Cancellation\Cancelled;
use OpenSwoole\Coroutine;

/**
 * Coroutine helpers that translate OpenSwoole's "cancellation = return-false +
 * isCanceled() flag" into the exception-based model aegis users expect.
 *
 * Phase 0 substrate finding (2026-04-30): OpenSwoole 26.x's Coroutine::cancel
 * does NOT raise an exception in the target coroutine the way native Swoole does.
 * It interrupts the suspended call (usleep returns false, channel pop returns
 * false, ...) and sets Coroutine::isCanceled() to true. The coroutine resumes
 * normally and is free to keep running. To preserve aegis semantics ("cancellation
 * propagates through await/sleep as a thrown exception"), every primitive in
 * this package that suspends checks isCanceled() on resume and throws Cancelled.
 */
final class Co
{
    public static function sleep(float $seconds): void
    {
        if ($seconds <= 0.0) {
            self::throwIfCanceled();
            return;
        }
        $ok = Coroutine::usleep((int) round($seconds * 1_000_000));
        if ($ok === false || Coroutine::isCanceled()) {
            throw new Cancelled('sleep interrupted by cancellation');
        }
    }

    public static function throwIfCanceled(): void
    {
        if (Coroutine::isCanceled()) {
            throw new Cancelled('coroutine cancelled');
        }
    }
}
