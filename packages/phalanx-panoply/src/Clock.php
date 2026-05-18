<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Minimal time seam for panoply's windowed-merge operations.
 *
 * Two distinct time sources are exposed deliberately:
 *
 * - `now()` is wall-clock time, appropriate for human-visible Cue `at`
 *   timestamps. It may move backward under NTP step adjustments.
 * - `nowMicroseconds()` is a monotonic counter, appropriate for window
 *   arithmetic inside {@see Stream::coalescing()}. It is strictly
 *   non-decreasing. The production implementation uses `hrtime(true)`;
 *   the epoch anchor is arbitrary — never compare it to wall-clock values.
 *
 * Inject {@see Clock\FrozenClock} in tests for deterministic windowing.
 * `FrozenClock::nowMicroseconds()` is the test-controlled monotonic seam;
 * advance it explicitly between stream items to simulate elapsed time.
 *
 * Intentionally narrow — only the two accessors that coalescing logic needs.
 */
interface Clock
{
    /**
     * Current wall-clock time. Suitable for human-visible Cue timestamps.
     * May move backward under NTP adjustments — do not use for window math.
     */
    public function now(): \DateTimeImmutable;

    /**
     * Monotonic microsecond counter. Used for window arithmetic inside
     * {@see Stream::coalescing()}. Guaranteed non-decreasing within a
     * process lifetime. The epoch anchor is arbitrary.
     */
    public function nowMicroseconds(): int;
}
