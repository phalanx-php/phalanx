<?php

declare(strict_types=1);

namespace Phalanx\Aegis\Tests\Unit\Recovery;

use Phalanx\Mark\Mark;
use Phalanx\Recovery\Backoff;
use Phalanx\Recovery\Jitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    #[Test]
    public function fixedReturnsConstantDelay(): void
    {
        $backoff = Backoff::fixed(Mark::ms(100));

        self::assertSame(100, $backoff->delayFor(0)->toMilliseconds());
        self::assertSame(100, $backoff->delayFor(1)->toMilliseconds());
        self::assertSame(100, $backoff->delayFor(5)->toMilliseconds());
    }

    #[Test]
    public function linearScalesByAttempt(): void
    {
        $backoff = Backoff::linear(Mark::ms(100));

        self::assertSame(100, $backoff->delayFor(0)->toMilliseconds());
        self::assertSame(200, $backoff->delayFor(1)->toMilliseconds());
        self::assertSame(300, $backoff->delayFor(2)->toMilliseconds());
    }

    #[Test]
    public function exponentialDoublesByAttempt(): void
    {
        $backoff = Backoff::exponential(Mark::ms(100));

        self::assertSame(100, $backoff->delayFor(0)->toMilliseconds());
        self::assertSame(200, $backoff->delayFor(1)->toMilliseconds());
        self::assertSame(400, $backoff->delayFor(2)->toMilliseconds());
        self::assertSame(800, $backoff->delayFor(3)->toMilliseconds());
    }

    #[Test]
    public function maxCapsDelay(): void
    {
        $backoff = Backoff::exponential(Mark::ms(100), Mark::ms(500));

        self::assertSame(400, $backoff->delayFor(2)->toMilliseconds());
        self::assertSame(500, $backoff->delayFor(3)->toMilliseconds());
        self::assertSame(500, $backoff->delayFor(10)->toMilliseconds());
    }

    #[Test]
    public function linearMaxCapsDelay(): void
    {
        $backoff = Backoff::linear(Mark::ms(100), Mark::ms(250));

        self::assertSame(250, $backoff->delayFor(5)->toMilliseconds());
    }

    #[Test]
    public function withJitterReturnsCopyWithNewJitter(): void
    {
        $backoff = Backoff::fixed(Mark::ms(100));
        $jittered = $backoff->withJitter(Jitter::percent(50, static fn(): float => 1.0));

        self::assertSame(100, $backoff->delayFor(0)->toMilliseconds());
        self::assertSame(150, $jittered->delayFor(0)->toMilliseconds());
    }

    #[Test]
    public function jitterIsAppliedInDelayFor(): void
    {
        $backoff = Backoff::fixed(
            Mark::ms(100),
            Jitter::percent(10, static fn(): float => 1.0),
        );

        self::assertSame(110, $backoff->delayFor(0)->toMilliseconds());
    }
}
