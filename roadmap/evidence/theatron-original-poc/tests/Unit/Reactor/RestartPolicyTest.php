<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Reactor;

use Phalanx\Theatron\Reactor\BackoffStrategy;
use Phalanx\Theatron\Reactor\OnExhausted;
use Phalanx\Theatron\Reactor\RestartPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RestartPolicyTest extends TestCase
{
    #[Test]
    public function default_factory_has_sane_defaults(): void
    {
        $policy = RestartPolicy::default();

        self::assertSame(3, $policy->maxRestarts);
        self::assertSame(60.0, $policy->window);
        self::assertSame(BackoffStrategy::None, $policy->backoff);
        self::assertSame(OnExhausted::Stop, $policy->onExhausted);
    }

    #[Test]
    public function never_factory_disables_restarts(): void
    {
        $policy = RestartPolicy::never();

        self::assertSame(0, $policy->maxRestarts);
    }

    #[Test]
    public function aggressive_factory_uses_exponential_backoff(): void
    {
        $policy = RestartPolicy::aggressive();

        self::assertSame(10, $policy->maxRestarts);
        self::assertSame(30.0, $policy->window);
        self::assertSame(BackoffStrategy::Exponential, $policy->backoff);
    }

    #[Test]
    public function aggressive_accepts_custom_params(): void
    {
        $policy = RestartPolicy::aggressive(maxRestarts: 5, window: 10.0);

        self::assertSame(5, $policy->maxRestarts);
        self::assertSame(10.0, $policy->window);
    }

    #[Test]
    public function constructor_accepts_all_params(): void
    {
        $policy = new RestartPolicy(
            maxRestarts: 7,
            window: 120.0,
            backoff: BackoffStrategy::Linear,
            onExhausted: OnExhausted::Escalate,
        );

        self::assertSame(7, $policy->maxRestarts);
        self::assertSame(120.0, $policy->window);
        self::assertSame(BackoffStrategy::Linear, $policy->backoff);
        self::assertSame(OnExhausted::Escalate, $policy->onExhausted);
    }
}
