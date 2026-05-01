<?php

declare(strict_types=1);

namespace Phalanx\Concurrency;

use Phalanx\Cancellation\Cancelled;
use Throwable;

/**
 * Retry strategy. Backoff in milliseconds with 10% jitter. shouldRetry() always
 * returns false for Cancelled — cancellation is never retried.
 */
final class RetryPolicy
{
    private const string STRATEGY_EXPONENTIAL = 'exponential';
    private const string STRATEGY_LINEAR = 'linear';
    private const string STRATEGY_FIXED = 'fixed';

    /** @var list<class-string<Throwable>> */
    private array $retryOn = [];

    private function __construct(
        public readonly int $attempts,
        public readonly string $strategy,
        public readonly float $baseDelayMs,
        public readonly float $maxDelayMs,
    ) {
    }

    public static function exponential(int $attempts, float $baseDelayMs = 100.0, float $maxDelayMs = 30000.0): self
    {
        return new self($attempts, self::STRATEGY_EXPONENTIAL, $baseDelayMs, $maxDelayMs);
    }

    public static function linear(int $attempts, float $baseDelayMs = 100.0, float $maxDelayMs = 30000.0): self
    {
        return new self($attempts, self::STRATEGY_LINEAR, $baseDelayMs, $maxDelayMs);
    }

    public static function fixed(int $attempts, float $delayMs = 1000.0): self
    {
        return new self($attempts, self::STRATEGY_FIXED, $delayMs, $delayMs);
    }

    /** @param class-string<Throwable> ...$exceptions */
    public function retryingOn(string ...$exceptions): self
    {
        $clone = clone $this;
        $clone->retryOn = $exceptions;
        return $clone;
    }

    public function calculateDelay(int $attempt): float
    {
        $base = match ($this->strategy) {
            self::STRATEGY_EXPONENTIAL => $this->baseDelayMs * (2 ** $attempt),
            self::STRATEGY_LINEAR => $this->baseDelayMs * ($attempt + 1),
            self::STRATEGY_FIXED => $this->baseDelayMs,
            default => $this->baseDelayMs,
        };
        $base = min($base, $this->maxDelayMs);
        $jitter = $base * 0.1 * (mt_rand() / mt_getrandmax());
        return $base + $jitter;
    }

    public function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof Cancelled) {
            return false;
        }
        if ($this->retryOn === []) {
            return true;
        }
        return array_any($this->retryOn, fn($type) => $e instanceof $type);
    }
}
