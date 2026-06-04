<?php

declare(strict_types=1);

namespace Phalanx\Mark;

use Closure;
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

    public static function fromMicrotime(float $timestamp): self
    {
        if ($timestamp < 0) {
            throw new InvalidArgumentException(
                sprintf('Microtime timestamp must be non-negative, got %s', $timestamp),
            );
        }

        return self::fromFloatScale($timestamp, 1_000_000_000, 'microtime');
    }

    public static function s(int|float $seconds): self
    {
        if ($seconds < 0) {
            throw new InvalidArgumentException(
                sprintf('Duration must be non-negative, got %s seconds', $seconds),
            );
        }

        if (is_int($seconds)) {
            if ($seconds > intdiv(PHP_INT_MAX, 1_000_000_000)) {
                throw new InvalidArgumentException(
                    sprintf('Duration overflow: %d seconds exceeds maximum representable nanoseconds', $seconds),
                );
            }

            return new self($seconds * 1_000_000_000);
        }

        return self::fromFloatScale($seconds, 1_000_000_000, 'seconds');
    }

    public static function ms(int|float $milliseconds): self
    {
        if ($milliseconds < 0) {
            throw new InvalidArgumentException(
                sprintf('Duration must be non-negative, got %s ms', $milliseconds),
            );
        }

        if (is_int($milliseconds)) {
            if ($milliseconds > intdiv(PHP_INT_MAX, 1_000_000)) {
                throw new InvalidArgumentException(
                    sprintf('Duration overflow: %d ms exceeds maximum representable nanoseconds', $milliseconds),
                );
            }

            return new self($milliseconds * 1_000_000);
        }

        return self::fromFloatScale($milliseconds, 1_000_000, 'ms');
    }

    public static function us(int $microseconds): self
    {
        if ($microseconds < 0) {
            throw new InvalidArgumentException(
                sprintf('Duration must be non-negative, got %d us', $microseconds),
            );
        }

        if ($microseconds > intdiv(PHP_INT_MAX, 1_000)) {
            throw new InvalidArgumentException(
                sprintf('Duration overflow: %d us exceeds maximum representable nanoseconds', $microseconds),
            );
        }

        return new self($microseconds * 1_000);
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

    public static function measure(Closure $work): MeasureResult
    {
        $start = self::now();
        $value = $work();

        return new MeasureResult($value, $start->elapsed());
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

    public function toSwooleMs(): int
    {
        return max(1, $this->toMilliseconds());
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

    private static function fromFloatScale(float $value, int $scale, string $unit): self
    {
        $ns = $value * $scale;

        if ($ns > PHP_INT_MAX) {
            throw new InvalidArgumentException(
                sprintf('Duration overflow: %s %s exceeds maximum representable nanoseconds', $value, $unit),
            );
        }

        return new self((int) $ns);
    }
}
