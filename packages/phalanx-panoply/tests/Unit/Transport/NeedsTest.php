<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Transport;

use Phalanx\Panoply\Transport\Needs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Needs::class)]
final class NeedsTest extends TestCase
{
    public function test_new_has_no_requirements(): void
    {
        $needs = Needs::new();

        self::assertFalse($needs->streamingRequired);
        self::assertFalse($needs->cancellableRequired);
        self::assertFalse($needs->backpressurePreferred);
        self::assertFalse($needs->partialJsonPreferred);
        self::assertNull($needs->maxIdleSeconds);
    }

    public function test_fluent_chain_accumulates_flags(): void
    {
        $needs = Needs::new()
            ->streaming()
            ->cancellable()
            ->preferBackpressure()
            ->preferPartialJson()
            ->withMaxIdleSeconds(30);

        self::assertTrue($needs->streamingRequired);
        self::assertTrue($needs->cancellableRequired);
        self::assertTrue($needs->backpressurePreferred);
        self::assertTrue($needs->partialJsonPreferred);
        self::assertSame(30, $needs->maxIdleSeconds);
    }

    public function test_each_setter_returns_new_instance(): void
    {
        $original = Needs::new();
        $streamed = $original->streaming();

        self::assertNotSame($original, $streamed);
        self::assertFalse($original->streamingRequired);
        self::assertTrue($streamed->streamingRequired);
    }

    public function test_max_idle_seconds_rejects_zero_or_negative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Needs::new()->withMaxIdleSeconds(0);
    }
}
