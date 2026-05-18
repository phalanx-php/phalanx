<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Immutable non-negative time span with microsecond internal representation.
 *
 * The internal unit is microseconds — matching PHP's `microtime(true)` scale
 * and OpenSwoole timer resolution. All factories accept the natural unit of
 * the caller; all accessors return the natural unit of the consumer.
 *
 * Final — canonical representation is a sealed int-microsecond storage;
 * subclassing would introduce subtly incompatible arithmetic.
 */
final class Duration
{
    public function __construct(
        private(set) int $microseconds,
    ) {
        if ($microseconds < 0) {
            throw new \InvalidArgumentException('Duration must be non-negative, got ' . $microseconds);
        }
    }

    public static function ms(int $milliseconds): self
    {
        return new self($milliseconds * 1_000);
    }

    public static function seconds(int $seconds): self
    {
        return new self($seconds * 1_000_000);
    }

    public static function microseconds(int $microseconds): self
    {
        return new self($microseconds);
    }

    public function toMilliseconds(): int
    {
        return intdiv($this->microseconds, 1_000);
    }

    public function toSeconds(): float
    {
        return $this->microseconds / 1_000_000;
    }

    public function toMicroseconds(): int
    {
        return $this->microseconds;
    }
}
