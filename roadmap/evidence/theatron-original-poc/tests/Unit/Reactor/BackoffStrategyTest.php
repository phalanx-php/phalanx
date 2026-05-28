<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Reactor;

use Phalanx\Theatron\Reactor\BackoffStrategy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackoffStrategyTest extends TestCase
{
    #[Test]
    public function none_always_returns_zero(): void
    {
        self::assertSame(0.0, BackoffStrategy::None->delay(1));
        self::assertSame(0.0, BackoffStrategy::None->delay(5));
        self::assertSame(0.0, BackoffStrategy::None->delay(100));
    }

    #[Test]
    public function linear_scales_with_attempt(): void
    {
        self::assertSame(1.0, BackoffStrategy::Linear->delay(1));
        self::assertSame(2.0, BackoffStrategy::Linear->delay(2));
        self::assertSame(5.0, BackoffStrategy::Linear->delay(5));
    }

    #[Test]
    public function linear_respects_base_delay(): void
    {
        self::assertSame(1.5, BackoffStrategy::Linear->delay(3, 0.5));
    }

    #[Test]
    public function exponential_doubles_per_attempt(): void
    {
        self::assertSame(1.0, BackoffStrategy::Exponential->delay(1));
        self::assertSame(2.0, BackoffStrategy::Exponential->delay(2));
        self::assertSame(4.0, BackoffStrategy::Exponential->delay(3));
        self::assertSame(8.0, BackoffStrategy::Exponential->delay(4));
    }

    #[Test]
    public function exponential_respects_base_delay(): void
    {
        self::assertSame(0.5, BackoffStrategy::Exponential->delay(1, 0.5));
        self::assertSame(1.0, BackoffStrategy::Exponential->delay(2, 0.5));
        self::assertSame(2.0, BackoffStrategy::Exponential->delay(3, 0.5));
    }
}
