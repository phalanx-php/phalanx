<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit\Http\Client;

use Phalanx\Stoa\Http\Client\RetryPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function noneNeverRetries(): void
    {
        $policy = RetryPolicy::none();

        self::assertFalse($policy->shouldRetry('GET', 0));
    }

    #[Test]
    public function idempotentMethodsRetryUpToMax(): void
    {
        $policy = RetryPolicy::exponential(maxRetries: 3);

        self::assertTrue($policy->shouldRetry('GET', 0));
        self::assertTrue($policy->shouldRetry('GET', 2));
        self::assertFalse($policy->shouldRetry('GET', 3));
    }

    #[Test]
    public function nonIdempotentMethodsNeverRetry(): void
    {
        $policy = RetryPolicy::exponential(maxRetries: 3);

        self::assertFalse($policy->shouldRetry('POST', 0));
        self::assertFalse($policy->shouldRetry('PATCH', 0));
    }

    #[Test]
    public function delayGrowsExponentiallyButCappedByMaxDelay(): void
    {
        $policy = new RetryPolicy(maxRetries: 5, initialDelay: 0.1, multiplier: 2.0, maxDelay: 1.0);

        self::assertEqualsWithDelta(0.1, $policy->delayFor(0), 0.0001);
        self::assertEqualsWithDelta(0.2, $policy->delayFor(1), 0.0001);
        self::assertEqualsWithDelta(0.4, $policy->delayFor(2), 0.0001);
        self::assertEqualsWithDelta(0.8, $policy->delayFor(3), 0.0001);
        self::assertEqualsWithDelta(1.0, $policy->delayFor(4), 0.0001);
        self::assertEqualsWithDelta(1.0, $policy->delayFor(10), 0.0001);
    }
}
