<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Transport;

use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Transport\Needs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NeedsTest extends TestCase
{
    #[Test]
    public function newHasNoRequirements(): void
    {
        $needs = Needs::new();

        self::assertFalse($needs->streamingRequired);
        self::assertFalse($needs->cancellableRequired);
        self::assertFalse($needs->backpressurePreferred);
        self::assertFalse($needs->partialJsonPreferred);
        self::assertNull($needs->maxIdleSeconds);
    }

    #[Test]
    public function fluentChainAccumulatesFlags(): void
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

    #[Test]
    public function eachSetterReturnsNewInstance(): void
    {
        $original = Needs::new();
        $streamed = $original->streaming();

        self::assertNotSame($original, $streamed);
        self::assertFalse($original->streamingRequired);
        self::assertTrue($streamed->streamingRequired);
    }

    #[Test]
    public function maxIdleSecondsRejectsZeroOrNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Needs::new()->withMaxIdleSeconds(0);
    }

    #[Test]
    public function hashIsStableAcrossReconstruction(): void
    {
        $a = Needs::new()->streaming()->cancellable();
        $b = Needs::new()->streaming()->cancellable();
        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function differentFlagsProduceDifferentHashes(): void
    {
        $a = Needs::new()->streaming();
        $b = Needs::new()->cancellable();
        self::assertNotSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIsA64CharacterHexString(): void
    {
        $hash = Canonical::of(Needs::new()->streaming()->cancellable());
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    }
}
