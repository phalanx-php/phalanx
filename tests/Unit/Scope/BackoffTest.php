<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Scope;

use Phalanx\Mark\Mark;
use Phalanx\Scope\Backoff;
use Phalanx\Scope\Jitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    #[Test]
    public function noneAndFixedProduceConstantDelays(): void
    {
        self::assertTrue(Backoff::none()->delayFor(0)->isZero());
        self::assertTrue(Backoff::none()->delayFor(5)->isZero());
        self::assertSame(50_000_000, Backoff::fixed(Mark::ms(50))->delayFor(0)->toNanoseconds());
        self::assertSame(50_000_000, Backoff::fixed(Mark::ms(50))->delayFor(9)->toNanoseconds());
    }

    #[Test]
    public function linearGrowsByAttemptAndRespectsTheCap(): void
    {
        $backoff = Backoff::linear(Mark::ms(100), Mark::ms(250));

        self::assertSame(100, $backoff->delayFor(0)->toMilliseconds());
        self::assertSame(200, $backoff->delayFor(1)->toMilliseconds());
        self::assertSame(250, $backoff->delayFor(2)->toMilliseconds());
    }

    #[Test]
    public function exponentialDoublesAndSaturatesAtTheCap(): void
    {
        $backoff = Backoff::exponential(Mark::ms(100), Mark::s(30));

        self::assertSame(100, $backoff->delayFor(0)->toMilliseconds());
        self::assertSame(200, $backoff->delayFor(1)->toMilliseconds());
        self::assertSame(400, $backoff->delayFor(2)->toMilliseconds());
        self::assertSame(30_000, $backoff->delayFor(60)->toMilliseconds());
    }

    #[Test]
    public function jitterAddsADeterministicOffsetThroughAnInjectedSource(): void
    {
        $backoff = Backoff::fixed(Mark::ms(100))->withJitter(Jitter::percent(50, static fn (): float => 1.0));

        self::assertSame(150, $backoff->delayFor(0)->toMilliseconds());

        $ranged = Backoff::fixed(Mark::ms(100))
            ->withJitter(Jitter::range(Mark::ms(10), Mark::ms(20), static fn (): float => 0.5));

        self::assertSame(115, $ranged->delayFor(0)->toMilliseconds());
    }
}
