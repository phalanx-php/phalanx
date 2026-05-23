<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Kit;

use Phalanx\Theatron\Kit\Metrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    #[Test]
    public function memoryFormatsBelowOneMegabyteAsKilobytes(): void
    {
        self::assertSame('1.0 KB', Metrics::memory(1024));
        self::assertSame('512 B', Metrics::memory(512));
    }

    #[Test]
    public function memoryFormatsLargeValuesAsKilobytes(): void
    {
        self::assertSame('1,024.0 KB', Metrics::memory(1_048_576));
        self::assertSame('2,560.0 KB', Metrics::memory(2 * 1_048_576 + 524_288));
    }

    #[Test]
    public function memoryDeltaPrefixesPlusForNonNegative(): void
    {
        self::assertSame('+1.0 KB', Metrics::memoryDelta(1024));
        self::assertSame('+0 B', Metrics::memoryDelta(0));
    }

    #[Test]
    public function memoryDeltaNegativeOmitsSign(): void
    {
        self::assertSame('1.0 KB', Metrics::memoryDelta(-1024));
    }

    #[Test]
    public function fpsReturnsZeroWhenElapsedIsNotPositive(): void
    {
        self::assertSame(0.0, Metrics::fps(100, 0.0));
        self::assertSame(0.0, Metrics::fps(100, -1.0));
    }

    #[Test]
    public function fpsCalculatesFramesPerSecond(): void
    {
        self::assertSame(60.0, Metrics::fps(60, 1.0));
        self::assertSame(30.0, Metrics::fps(60, 2.0));
    }

    #[Test]
    public function uptimeFormatsSecondsDirectlyWhenBelowOneMinute(): void
    {
        self::assertSame('5.0s', Metrics::uptime(5.0));
        self::assertSame('59.9s', Metrics::uptime(59.9));
    }

    #[Test]
    public function uptimeFormatsMinutesAndRemainingSeconds(): void
    {
        self::assertSame('1m00.0s', Metrics::uptime(60.0));
        self::assertSame('2m30.0s', Metrics::uptime(150.0));
    }

    #[Test]
    public function memoryFormatsZeroBytes(): void
    {
        self::assertSame('0 B', Metrics::memory(0));
    }
}
