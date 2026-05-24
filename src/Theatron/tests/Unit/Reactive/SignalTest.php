<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\Reactive;

use Phalanx\Scope\Scope;
use Phalanx\Theatron\Reactive\Signal;
use Phalanx\Theatron\Reactive\Tracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SignalTest extends TestCase
{
    #[Test]
    public function readRecordsAccessInTracker(): void
    {
        $signal = new Signal(42);

        $frame = Tracker::push();
        $read = $signal->get();
        $deps = Tracker::pop($frame);

        self::assertSame(42, $read);
        self::assertCount(1, $deps);
        self::assertSame($signal, $deps[0]);
    }

    #[Test]
    public function setNotifiesSubscribers(): void
    {
        $signal = new Signal(1);
        $calls = 0;

        $signal->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $signal->set(2);

        self::assertSame(1, $calls);
    }

    #[Test]
    public function equalitySkipSuppressesNotification(): void
    {
        $signal = new Signal('hello');
        $calls = 0;

        $signal->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $signal->set('hello');

        self::assertSame(0, $calls);
    }

    #[Test]
    public function disposalBlocksWrites(): void
    {
        $signal = new Signal(0);
        $signal->dispose();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot write to a disposed signal.');

        $signal->set(1);
    }

    #[Test]
    public function disposalBlocksSubscribes(): void
    {
        $signal = new Signal(0);
        $signal->dispose();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot subscribe to a disposed signal.');

        $signal->subscribe(static function (): void {
        });
    }

    #[Test]
    public function disposedFlagIsSet(): void
    {
        $signal = new Signal(0);
        self::assertFalse($signal->isDisposed);

        $signal->dispose();
        self::assertTrue($signal->isDisposed);
    }

    #[Test]
    public function subscriberCountTracksSubscriptions(): void
    {
        $signal = new Signal(0);
        self::assertSame(0, $signal->subscriberCount);

        $sub = $signal->subscribe(static function (): void {
        });
        self::assertSame(1, $signal->subscriberCount);

        $sub->dispose();
        self::assertSame(0, $signal->subscriberCount);
    }

    #[Test]
    public function multipleSubscribersAllFire(): void
    {
        $signal = new Signal(0);
        $a = 0;
        $b = 0;

        $signal->subscribe(static function () use (&$a): void {
            $a++;
        });
        $signal->subscribe(static function () use (&$b): void {
            $b++;
        });

        $signal->set(1);

        self::assertSame(1, $a);
        self::assertSame(1, $b);
    }

    #[Test]
    public function subscriberUnsubscribingDuringNotifyIsSafe(): void
    {
        $signal = new Signal(0);
        $calls = 0;

        $sub = $signal->subscribe(static function () use (&$sub, &$calls): void {
            $calls++;
            /** @var \Phalanx\Theatron\Reactive\SignalSubscription $sub */
            $sub->dispose();
        });

        $signal->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $signal->set(1);

        self::assertSame(2, $calls);
        self::assertSame(1, $signal->subscriberCount);
    }

    #[Test]
    public function setCanReceiveStaticUpdaterClosure(): void
    {
        $signal = new Signal(1);
        $calls = 0;

        $signal->subscribe(static function () use (&$calls): void {
            $calls++;
        });

        $signal->set(static fn(int $current): int => $current + 41);

        self::assertSame(42, $signal->get());
        self::assertSame(1, $calls);
    }

    #[Test]
    public function setPassesScopeToUpdaterWhenProvided(): void
    {
        $signal = new Signal('idle');
        $scope = $this->createStub(Scope::class);

        $signal->set(static fn(string $current, ?Scope $given): string => $given === $scope
            ? $current . ':scoped'
            : 'wrong-scope', $scope);

        self::assertSame('idle:scoped', $signal->get());
    }

    #[Test]
    public function setStoresNonClosureCallablesAsValues(): void
    {
        $signal = new Signal(null);

        $signal->set('strlen');

        self::assertSame('strlen', $signal->get());
    }

    #[Test]
    public function setStoresInvokableObjectsAsValues(): void
    {
        $signal = new Signal(null);
        $invokable = new class () {
            public function __invoke(): string
            {
                return 'called';
            }
        };

        $signal->set($invokable);

        self::assertSame($invokable, $signal->get());
    }

    #[Test]
    public function nonStaticUpdaterClosureThrows(): void
    {
        $signal = new Signal(0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Signal updater closures must be static closures.');

        $signal->set(fn(int $current): int => $current + 1);
    }

    #[Test]
    public function nonStaticSubscriberThrows(): void
    {
        $signal = new Signal(0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Signal subscribers must be static closures.');

        $signal->subscribe(function (): void {
        });
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
