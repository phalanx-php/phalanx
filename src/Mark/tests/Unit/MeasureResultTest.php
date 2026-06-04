<?php

declare(strict_types=1);

namespace Phalanx\Mark\Tests\Unit;

use Phalanx\Mark\Mark;
use Phalanx\Mark\MeasureResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MeasureResultTest extends TestCase
{
    #[Test]
    public function measureReturnsValueAndElapsed(): void
    {
        $result = Mark::measure(static fn(): int => 42);

        self::assertSame(42, $result->value);
        self::assertFalse($result->elapsed->lt(Mark::zero()));
    }

    #[Test]
    public function measurePropagatesException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        Mark::measure(static function (): never {
            throw new RuntimeException('boom');
        });
    }

    #[Test]
    public function propertiesExposed(): void
    {
        $elapsed = Mark::ms(100);
        $result = new MeasureResult('hello', $elapsed);

        self::assertSame('hello', $result->value);
        self::assertTrue($result->elapsed->eq($elapsed));
    }
}
