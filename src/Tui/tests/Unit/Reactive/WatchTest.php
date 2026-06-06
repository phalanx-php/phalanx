<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Reactive;

use Phalanx\Tui\Reactive\Resource;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Reactive\Tracker;
use Phalanx\Tui\Reactive\Watch;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WatchTest extends TestCase
{
    #[Test]
    public function firesEffectOnDepChange(): void
    {
        $sig = new Signal(1);
        $fired = 0;

        $watch = new Watch(
            static fn(): int => $sig->get(),
            static function () use (&$fired): void {
                $fired++;
            },
        );

        $sig->set(null, 2);
        self::assertSame(1, $fired);

        unset($watch);
    }

    #[Test]
    public function effectReceivesNewAndOldValues(): void
    {
        $sig = new Signal(10);
        $capturedNew = null;
        $capturedOld = null;

        $watch = new Watch(
            static fn(): int => $sig->get(),
            static function (mixed $new, mixed $old) use (&$capturedNew, &$capturedOld): void {
                $capturedNew = $new;
                $capturedOld = $old;
            },
        );

        $sig->set(null, 20);

        self::assertSame(20, $capturedNew);
        self::assertSame(10, $capturedOld);

        unset($watch);
    }

    #[Test]
    public function effectNotFiredWhenValueUnchanged(): void
    {
        $sig = new Signal(5);
        $fired = 0;

        new Watch(
            static fn(): int => $sig->get(),
            static function () use (&$fired): void {
                $fired++;
            },
        );

        $sig->set(null, 5);
        self::assertSame(0, $fired);
    }

    #[Test]
    public function disposeStopsEffects(): void
    {
        $sig = new Signal(1);
        $fired = 0;

        $watch = new Watch(
            static fn(): int => $sig->get(),
            static function () use (&$fired): void {
                $fired++;
            },
        );

        $watch->dispose();
        $sig->set(null, 2);

        self::assertSame(0, $fired);
    }

    #[Test]
    public function reEntrancyGuardPreventsInfiniteLoop(): void
    {
        $sig = new Signal(1);
        $effectCount = 0;

        $watch = new Watch(
            static fn(): int => $sig->get(),
            static function (mixed $new) use ($sig, &$effectCount): void {
                $effectCount++;
                $sig->set(null, $new + 100);
            },
        );

        $sig->set(null, 2);

        self::assertSame(1, $effectCount);

        unset($watch);
    }

    #[Test]
    public function firesEffectOnResourceChange(): void
    {
        $resource = new Resource(
            fetcher: static fn(): iterable => ['streamed'],
        );
        $fired = 0;

        $watch = new Watch(
            static fn(): string => $resource->buffer,
            static function () use (&$fired): void {
                $fired++;
            },
        );

        $resource->stream();

        self::assertSame(1, $fired);

        unset($watch);
    }

    #[Test]
    public function nonStaticSelectorThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Watch selector must be a static closure.');

        new Watch(
            fn(): int => 1,
            static function (): void {
            },
        );
    }

    #[Test]
    public function nonStaticEffectThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Watch effect must be a static closure.');

        new Watch(
            static fn(): int => 1,
            function (): void {
            },
        );
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
