<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Http\Client;

/**
 * Exponential-backoff retry policy for outbound HTTP/1.1 requests.
 *
 * Idempotent methods only (GET, HEAD, OPTIONS, DELETE, PUT) are retried.
 * Non-idempotent methods (POST, PATCH, CONNECT, TRACE) bypass retries
 * regardless of the failure mode — a stalled POST may have committed
 * server-side state, and replaying it is unsafe without per-call opt-in.
 */
final readonly class RetryPolicy
{
    public const array IDEMPOTENT_METHODS = ['GET', 'HEAD', 'OPTIONS', 'DELETE', 'PUT'];

    public function __construct(
        public int $maxRetries = 0,
        public float $initialDelay = 0.1,
        public float $multiplier = 2.0,
        public float $maxDelay = 5.0,
    ) {
    }

    public static function none(): self
    {
        return new self(maxRetries: 0);
    }

    public static function exponential(int $maxRetries = 3, float $initialDelay = 0.1): self
    {
        return new self(maxRetries: $maxRetries, initialDelay: $initialDelay);
    }

    public function shouldRetry(string $method, int $attempt): bool
    {
        if ($attempt >= $this->maxRetries) {
            return false;
        }

        return in_array(strtoupper($method), self::IDEMPOTENT_METHODS, true);
    }

    public function delayFor(int $attempt): float
    {
        $delay = $this->initialDelay * ($this->multiplier ** $attempt);

        return min($delay, $this->maxDelay);
    }
}
