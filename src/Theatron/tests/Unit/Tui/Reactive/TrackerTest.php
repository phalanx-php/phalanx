<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Tui\Reactive;

use Phalanx\Theatron\Tui\Reactive\Signal;
use Phalanx\Theatron\Tui\Reactive\Tracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TrackerTest extends TestCase
{
    #[Test]
    public function isTrackingFalseWhenNoFrame(): void
    {
        self::assertFalse(Tracker::isTracking());
    }

    #[Test]
    public function isTrackingTrueAfterPush(): void
    {
        $frame = Tracker::push();
        self::assertTrue(Tracker::isTracking());
        Tracker::pop($frame);
    }

    #[Test]
    public function pushAndPopReturnDeps(): void
    {
        $sig = new Signal(1);

        $frame = Tracker::push();
        Tracker::recordAccess($sig);
        $deps = Tracker::pop($frame);

        self::assertSame([$sig], $deps);
        self::assertFalse(Tracker::isTracking());
    }

    #[Test]
    public function recordAccessDeduplicates(): void
    {
        $sig = new Signal(1);

        $frame = Tracker::push();
        Tracker::recordAccess($sig);
        Tracker::recordAccess($sig);
        Tracker::recordAccess($sig);
        $deps = Tracker::pop($frame);

        self::assertCount(1, $deps);
    }

    #[Test]
    public function nestedFramesAreSeparate(): void
    {
        $sig1 = new Signal(1);
        $sig2 = new Signal(2);

        $outer = Tracker::push();
        Tracker::recordAccess($sig1);

        $inner = Tracker::push();
        Tracker::recordAccess($sig2);
        $innerDeps = Tracker::pop($inner);

        $outerDeps = Tracker::pop($outer);

        self::assertSame([$sig2], $innerDeps);
        self::assertSame([$sig1], $outerDeps);
    }

    #[Test]
    public function recordAccessNoopWhenNotTracking(): void
    {
        $sig = new Signal(1);
        Tracker::recordAccess($sig);

        self::assertFalse(Tracker::isTracking());
    }

    #[Test]
    public function popInvalidFrameThrows(): void
    {
        $this->expectException(RuntimeException::class);

        Tracker::pop(99);
    }

    protected function setUp(): void
    {
        while (Tracker::isTracking()) {
            try {
                Tracker::pop(0);
            } catch (RuntimeException) {
                break;
            }
        }
    }
}
