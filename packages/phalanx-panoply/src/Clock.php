<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Minimal time seam for panoply's windowed-merge operations.
 *
 * Inject {@see Clock\FrozenClock} in tests for deterministic windowing;
 * the default implementation is {@see Clock\SystemClock}, which reads
 * `microtime(true)`.
 *
 * Intentionally narrow — only the two accessors that coalescing logic needs.
 */
interface Clock
{
    /**
     * Current wall-clock time.
     */
    public function now(): \DateTimeImmutable;

    /**
     * Current epoch in microseconds. Used for sub-millisecond window
     * arithmetic inside {@see Stream::coalescing()}.
     */
    public function nowMicroseconds(): int;
}
