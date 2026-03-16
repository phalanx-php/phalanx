<?php

declare(strict_types=1);

namespace Convoy\Concurrency;

use Convoy\Exception\CancelledException;
use Throwable;

final class RetryPolicy
{
    private const float DEFAULT_BASE_DELAY_MS = 100.0;
    private const float DEFAULT_MAX_DELAY_MS = 30000.0;

    public function __construct(
        public private(set) int $attempts,
        public private(set) string $backoff,
        public private(set) float $baseDelayMs,
        public private(set) float $maxDelayMs,
        /** @var list<class-string<Throwable>> */
        public private(set) array $retryOn = [],
    ) {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('Attempts must be at least 1');
        }
        if (!in_array($backoff, ['exponential', 'linear', 'fixed'], true)) {
            throw new \InvalidArgumentException("Invalid backoff strategy: $backoff");
        }
    }

    public static function exponential(
        int $attempts,
        float $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        float $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ): self {
        return new self($attempts, 'exponential', $baseDelayMs, $maxDelayMs);
    }

    public static function linear(
        int $attempts,
        float $baseDelayMs = self::DEFAULT_BASE_DELAY_MS,
        float $maxDelayMs = self::DEFAULT_MAX_DELAY_MS,
    ): self {
        return new self($attempts, 'linear', $baseDelayMs, $maxDelayMs);
    }

    public static function fixed(
        int $attempts,
        float $delayMs = 1000.0,
    ): self {
        return new self($attempts, 'fixed', $delayMs, $delayMs);
    }

    /** @param class-string<Throwable> ...$exceptions */
    public function retryingOn(string ...$exceptions): self
    {
        $clone = clone $this;
        $clone->retryOn = array_values($exceptions);
        return $clone;
    }

    public function calculateDelay(int $attempt): float
    {
        if ($attempt < 1) {
            return 0.0;
        }

        $delay = match ($this->backoff) {
            'exponential' => $this->baseDelayMs * (2 ** ($attempt - 1)),
            'linear' => $this->baseDelayMs * $attempt,
            default => $this->baseDelayMs,
        };

        $jitter = $delay * 0.1 * (random_int(0, 100) / 100);

        return min($delay + $jitter, $this->maxDelayMs);
    }

    public function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof CancelledException) {
            return false;
        }

        if ($this->retryOn === []) {
            return true;
        }
        return array_any($this->retryOn, fn($exceptionClass): bool => $e instanceof $exceptionClass);
    }
}
