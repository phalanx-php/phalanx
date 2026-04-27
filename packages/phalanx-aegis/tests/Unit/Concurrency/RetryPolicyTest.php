<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Concurrency;

use Phalanx\Concurrency\RetryPolicy;
use Phalanx\Exception\CancelledException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function exponential_backoff_calculation(): void
    {
        $policy = RetryPolicy::exponential(5, baseDelayMs: 100.0, maxDelayMs: 5000.0);

        // Attempt 0 should return 0
        $this->assertSame(0.0, $policy->calculateDelay(0));

        // Attempt 1: ~100ms (base)
        $delay1 = $policy->calculateDelay(1);
        $this->assertGreaterThanOrEqual(100.0, $delay1);
        $this->assertLessThanOrEqual(110.0, $delay1); // 10% jitter

        // Attempt 2: ~200ms (2^1 * base)
        $delay2 = $policy->calculateDelay(2);
        $this->assertGreaterThanOrEqual(200.0, $delay2);
        $this->assertLessThanOrEqual(220.0, $delay2);

        // Attempt 3: ~400ms (2^2 * base)
        $delay3 = $policy->calculateDelay(3);
        $this->assertGreaterThanOrEqual(400.0, $delay3);
        $this->assertLessThanOrEqual(440.0, $delay3);

        // Attempt 4: ~800ms (2^3 * base)
        $delay4 = $policy->calculateDelay(4);
        $this->assertGreaterThanOrEqual(800.0, $delay4);
        $this->assertLessThanOrEqual(880.0, $delay4);
    }

    #[Test]
    public function linear_backoff_calculation(): void
    {
        $policy = RetryPolicy::linear(5, baseDelayMs: 100.0, maxDelayMs: 350.0);

        // Attempt 1: 100ms
        $delay1 = $policy->calculateDelay(1);
        $this->assertGreaterThanOrEqual(100.0, $delay1);
        $this->assertLessThanOrEqual(110.0, $delay1);

        // Attempt 2: 200ms
        $delay2 = $policy->calculateDelay(2);
        $this->assertGreaterThanOrEqual(200.0, $delay2);
        $this->assertLessThanOrEqual(220.0, $delay2);

        // Attempt 3: 300ms
        $delay3 = $policy->calculateDelay(3);
        $this->assertGreaterThanOrEqual(300.0, $delay3);
        $this->assertLessThanOrEqual(330.0, $delay3);

        // Attempt 4: capped at 350ms (maxDelayMs)
        $delay4 = $policy->calculateDelay(4);
        $this->assertLessThanOrEqual(350.0, $delay4);
    }

    #[Test]
    public function fixed_backoff_calculation(): void
    {
        $policy = RetryPolicy::fixed(3, delayMs: 500.0);

        // All attempts should return ~500ms (with jitter)
        $delay1 = $policy->calculateDelay(1);
        $delay2 = $policy->calculateDelay(2);
        $delay3 = $policy->calculateDelay(3);

        $this->assertGreaterThanOrEqual(500.0, $delay1);
        $this->assertLessThanOrEqual(550.0, $delay1);

        $this->assertGreaterThanOrEqual(500.0, $delay2);
        $this->assertLessThanOrEqual(550.0, $delay2);

        $this->assertGreaterThanOrEqual(500.0, $delay3);
        $this->assertLessThanOrEqual(550.0, $delay3);
    }

    #[Test]
    public function should_retry_with_empty_retry_on_retries_all(): void
    {
        $policy = RetryPolicy::exponential(3);

        // Empty retryOn = retry all exceptions
        $this->assertTrue($policy->shouldRetry(new RuntimeException('test')));
        $this->assertTrue($policy->shouldRetry(new InvalidArgumentException('test')));
        $this->assertTrue($policy->shouldRetry(new \LogicException('test')));
    }

    #[Test]
    public function should_retry_with_specific_exceptions(): void
    {
        $policy = RetryPolicy::exponential(3)->retryingOn(
            RuntimeException::class,
        );

        $this->assertTrue($policy->shouldRetry(new RuntimeException('test')));
        $this->assertFalse($policy->shouldRetry(new InvalidArgumentException('test')));
        $this->assertFalse($policy->shouldRetry(new \LogicException('test')));
    }

    #[Test]
    public function should_retry_matches_subclasses(): void
    {
        $policy = RetryPolicy::exponential(3)->retryingOn(
            RuntimeException::class,
        );

        // UnexpectedValueException extends RuntimeException
        $this->assertTrue($policy->shouldRetry(new UnexpectedValueException('test')));
    }

    #[Test]
    public function never_retries_cancelled_exception(): void
    {
        // Even with empty retryOn (retry all)
        $policy = RetryPolicy::exponential(3);
        $this->assertFalse($policy->shouldRetry(new CancelledException()));

        // Even if CancelledException is explicitly in retryOn
        $policyWithCancelled = RetryPolicy::exponential(3)->retryingOn(
            CancelledException::class,
            RuntimeException::class,
        );
        $this->assertFalse($policyWithCancelled->shouldRetry(new CancelledException()));
    }

    #[Test]
    public function rejects_invalid_attempts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attempts must be at least 1');

        RetryPolicy::exponential(0);
    }

    #[Test]
    public function rejects_invalid_backoff_strategy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid backoff strategy: invalid');

        new RetryPolicy(
            attempts: 3,
            backoff: 'invalid',
            baseDelayMs: 100.0,
            maxDelayMs: 1000.0,
        );
    }

    #[Test]
    public function retrying_on_returns_new_instance(): void
    {
        $policy1 = RetryPolicy::exponential(3);
        $policy2 = $policy1->retryingOn(RuntimeException::class);

        $this->assertNotSame($policy1, $policy2);
        $this->assertEmpty($policy1->retryOn);
        $this->assertEquals([RuntimeException::class], $policy2->retryOn);
    }

    #[Test]
    public function factory_methods_set_correct_properties(): void
    {
        $exponential = RetryPolicy::exponential(3);
        $this->assertSame(3, $exponential->attempts);
        $this->assertSame('exponential', $exponential->backoff);

        $linear = RetryPolicy::linear(5);
        $this->assertSame(5, $linear->attempts);
        $this->assertSame('linear', $linear->backoff);

        $fixed = RetryPolicy::fixed(2, 500.0);
        $this->assertSame(2, $fixed->attempts);
        $this->assertSame('fixed', $fixed->backoff);
        $this->assertSame(500.0, $fixed->baseDelayMs);
    }
}
