<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Kit;

use Phalanx\Theatron\Kit\Metrics;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MetricsTest extends TestCase
{
    #[Test]
    public function memory_formats_kilobytes(): void
    {
        self::assertSame('512.0K', Metrics::memory(524_288));
    }

    #[Test]
    public function memory_formats_megabytes(): void
    {
        self::assertSame('2.5M', Metrics::memory(2_621_440));
    }

    #[Test]
    public function memory_formats_exact_megabyte_boundary(): void
    {
        self::assertSame('1.0M', Metrics::memory(1_048_576));
    }

    #[Test]
    public function memory_formats_zero(): void
    {
        self::assertSame('0.0K', Metrics::memory(0));
    }

    #[Test]
    public function memory_delta_positive(): void
    {
        self::assertSame('+512.0K', Metrics::memoryDelta(524_288));
    }

    #[Test]
    public function memory_delta_negative(): void
    {
        self::assertSame('1.0M', Metrics::memoryDelta(-1_048_576));
    }

    #[Test]
    public function memory_delta_zero(): void
    {
        self::assertSame('+0.0K', Metrics::memoryDelta(0));
    }

    #[Test]
    public function fps_calculates_rate(): void
    {
        self::assertSame(30.0, Metrics::fps(60, 2.0));
    }

    #[Test]
    public function fps_returns_zero_for_zero_elapsed(): void
    {
        self::assertSame(0.0, Metrics::fps(100, 0.0));
    }

    #[Test]
    public function fps_returns_zero_for_negative_elapsed(): void
    {
        self::assertSame(0.0, Metrics::fps(100, -1.0));
    }

    #[Test]
    public function uptime_formats_seconds(): void
    {
        self::assertSame('45.3s', Metrics::uptime(45.3));
    }

    #[Test]
    public function uptime_formats_minutes_and_seconds(): void
    {
        self::assertSame('2m30.0s', Metrics::uptime(150.0));
    }

    #[Test]
    public function uptime_formats_zero(): void
    {
        self::assertSame('0.0s', Metrics::uptime(0.0));
    }
}
