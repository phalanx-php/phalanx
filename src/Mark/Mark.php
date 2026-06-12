<?php

declare(strict_types=1);

namespace Phalanx\Mark;

use InvalidArgumentException;

final class Mark
{
    private function __construct(
        private(set) int $nanoseconds,
    ) {
    }

    public static function now(): self
    {
        return new self(hrtime(true));
    }

    public static function h(int|float $hours): self
    {
        return self::scaled($hours, 3_600_000_000_000, 'hours');
    }

    public static function m(int|float $minutes): self
    {
        return self::scaled($minutes, 60_000_000_000, 'minutes');
    }

    public static function s(int|float $seconds): self
    {
        return self::scaled($seconds, 1_000_000_000, 'seconds');
    }

    public static function ms(int|float $milliseconds): self
    {
        return self::scaled($milliseconds, 1_000_000, 'ms');
    }

    public static function us(int $microseconds): self
    {
        return self::scaled($microseconds, 1_000, 'us');
    }

    public static function ns(int $nanoseconds): self
    {
        if ($nanoseconds < 0) {
            throw new InvalidArgumentException(
                sprintf('Duration must be non-negative, got %d ns', $nanoseconds),
            );
        }

        return new self($nanoseconds);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function plus(self $other): self
    {
        if ($other->nanoseconds > PHP_INT_MAX - $this->nanoseconds) {
            throw new InvalidArgumentException('Mark overflow: sum exceeds maximum representable nanoseconds');
        }

        return new self($this->nanoseconds + $other->nanoseconds);
    }

    public function minus(self $other): self
    {
        return new self(max(0, $this->nanoseconds - $other->nanoseconds));
    }

    public function max(self $other): self
    {
        return $this->nanoseconds >= $other->nanoseconds ? $this : $other;
    }

    public function min(self $other): self
    {
        return $this->nanoseconds <= $other->nanoseconds ? $this : $other;
    }

    public function elapsed(): self
    {
        return self::now()->minus($this);
    }

    public function since(self $earlier): self
    {
        return $this->minus($earlier);
    }

    public function until(self $later): self
    {
        return $later->minus($this);
    }

    public function toSeconds(): float
    {
        return $this->nanoseconds / 1_000_000_000;
    }

    public function toMilliseconds(): int
    {
        return intdiv($this->nanoseconds, 1_000_000);
    }

    public function toMicroseconds(): int
    {
        return intdiv($this->nanoseconds, 1_000);
    }

    public function toNanoseconds(): int
    {
        return $this->nanoseconds;
    }

    public function isZero(): bool
    {
        return $this->nanoseconds === 0;
    }

    public function isPositive(): bool
    {
        return $this->nanoseconds > 0;
    }

    public function gt(self $other): bool
    {
        return $this->nanoseconds > $other->nanoseconds;
    }

    public function lt(self $other): bool
    {
        return $this->nanoseconds < $other->nanoseconds;
    }

    public function gte(self $other): bool
    {
        return $this->nanoseconds >= $other->nanoseconds;
    }

    public function lte(self $other): bool
    {
        return $this->nanoseconds <= $other->nanoseconds;
    }

    public function eq(self $other): bool
    {
        return $this->nanoseconds === $other->nanoseconds;
    }

    private static function scaled(int|float $value, int $scale, string $unit): self
    {
        if ($value < 0) {
            throw new InvalidArgumentException(
                sprintf('Duration must be non-negative, got %s %s', $value, $unit),
            );
        }

        if (is_int($value)) {
            if ($value > intdiv(PHP_INT_MAX, $scale)) {
                throw new InvalidArgumentException(
                    sprintf('Duration overflow: %d %s exceeds maximum representable nanoseconds', $value, $unit),
                );
            }

            return new self($value * $scale);
        }

        $ns = $value * $scale;

        if ($ns > PHP_INT_MAX) {
            throw new InvalidArgumentException(
                sprintf('Duration overflow: %s %s exceeds maximum representable nanoseconds', $value, $unit),
            );
        }

        return new self((int) $ns);
    }
}
