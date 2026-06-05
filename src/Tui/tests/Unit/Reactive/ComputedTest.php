<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Reactive;

use Phalanx\Tui\Reactive\Computed;
use Phalanx\Tui\Reactive\Resource;
use Phalanx\Tui\Reactive\Signal;
use Phalanx\Tui\Reactive\Tracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ComputedTest extends TestCase
{
    #[Test]
    public function lazyEvaluation(): void
    {
        $evals = 0;
        $sig = new Signal(5);

        $computed = new Computed(static function () use ($sig, &$evals): int {
            $evals++;
            return $sig->get() * 2;
        });

        self::assertSame(0, $evals);

        $result = $computed->value;
        self::assertSame(10, $result);
        self::assertSame(1, $evals);
    }

    #[Test]
    public function cachesResultUntilDepChanges(): void
    {
        $evals = 0;
        $sig = new Signal(3);

        $computed = new Computed(static function () use ($sig, &$evals): int {
            $evals++;
            return $sig->get() + 1;
        });

        self::assertSame(4, $computed->value);
        self::assertSame(4, $computed->value);
        self::assertSame(1, $evals);

        $sig->set(10);
        self::assertSame(11, $computed->value);
        self::assertSame(2, $evals);
    }

    #[Test]
    public function autoRecomputesOnDepChange(): void
    {
        $sig = new Signal(2);
        $computed = new Computed(static fn(): int => $sig->get() * 3);

        self::assertSame(6, $computed->value);

        $sig->set(4);
        self::assertSame(12, $computed->value);
    }

    #[Test]
    public function circularDependencyThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular computed dependency detected.');

        $computed = null;
        $computed = new Computed(static function () use (&$computed): int {
            /** @var Computed $computed */
            return $computed->value + 1;
        });

        self::assertIsInt($computed->value);
    }

    #[Test]
    public function disposalCascadesDepSubscriptions(): void
    {
        $sig = new Signal(1);
        $evals = 0;

        $computed = new Computed(static function () use ($sig, &$evals): int {
            $evals++;
            return $sig->get();
        });

        self::assertSame(1, $computed->value);
        self::assertSame(1, $evals);

        $computed->dispose();
        self::assertTrue($computed->isDisposed);

        $sig->set(2);
        self::assertSame(1, $evals);
    }

    #[Test]
    public function nonStaticFactoryThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Computed factory must be a static closure.');

        new Computed(fn(): int => 1);
    }

    #[Test]
    public function subscriberNotifiedOnDirty(): void
    {
        $sig = new Signal(1);
        $notified = 0;

        $computed = new Computed(static fn(): int => $sig->get() + 10);
        self::assertSame(11, $computed->value);

        $computed->subscribe(static function () use (&$notified): void {
            $notified++;
        });

        $sig->set(5);
        self::assertSame(1, $notified);
    }

    #[Test]
    public function chainedDependencyRecomputes(): void
    {
        $sig = new Signal(2);
        $b = new Computed(static fn(): int => $sig->get() * 3);
        $c = new Computed(static fn(): int => $b->value + 10);

        self::assertSame(16, $c->value);

        $sig->set(5);
        self::assertSame(25, $c->value);
    }

    #[Test]
    public function resourceDependencyRecomputes(): void
    {
        $resource = new Resource(
            fetcher: static fn(): iterable => ['streamed'],
        );

        $computed = new Computed(static fn(): string => $resource->buffer);

        self::assertSame('', $computed->value);

        $resource->stream();

        self::assertSame('streamed', $computed->value);
    }

    #[Test]
    public function disposalCleansUpSubscribers(): void
    {
        $sig = new Signal(1);
        $computed = new Computed(static fn(): int => $sig->get());

        $notified = 0;
        $computed->subscribe(static function () use (&$notified): void {
            $notified++;
        });

        self::assertSame(1, $computed->value);

        $computed->dispose();
        self::assertSame(0, $computed->subscriberCount);

        $sig->set(2);
        self::assertSame(0, $notified);
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
