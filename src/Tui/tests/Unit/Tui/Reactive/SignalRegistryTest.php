<?php

declare(strict_types=1);

namespace Phalanx\Tui\Tests\Unit\Tui\Reactive;

use Phalanx\Tui\Tui\Reactive\Signal;
use Phalanx\Tui\Tui\Reactive\SignalRegistry;
use Phalanx\Tui\Tui\Reactive\SignalSnapshot;
use Phalanx\Tui\Tui\Reactive\Tracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SignalRegistryTest extends TestCase
{
    #[Test]
    public function registerAndSnapshotRoundtrip(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(42);
        $registry->register($signal, 'answer');

        $entries = $registry->snapshot();

        self::assertCount(1, $entries);
        self::assertInstanceOf(SignalSnapshot::class, $entries[0]);
        self::assertSame('answer', $entries[0]->label);
        self::assertSame('42', $entries[0]->value);
        self::assertSame(0, $entries[0]->subscriberCount);
        self::assertFalse($entries[0]->isDisposed);
    }

    #[Test]
    public function formatValueNull(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(null);
        $registry->register($signal, 'n');

        $entries = $registry->snapshot();
        self::assertSame('null', $entries[0]->value);
    }

    #[Test]
    public function formatValueTrue(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(true);
        $registry->register($signal, 'b');

        $entries = $registry->snapshot();
        self::assertSame('true', $entries[0]->value);
    }

    #[Test]
    public function formatValueFalse(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(false);
        $registry->register($signal, 'b');

        $entries = $registry->snapshot();
        self::assertSame('false', $entries[0]->value);
    }

    #[Test]
    public function formatValueInt(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(7);
        $registry->register($signal, 'i');

        $entries = $registry->snapshot();
        self::assertSame('7', $entries[0]->value);
    }

    #[Test]
    public function formatValueFloat(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(3.14);
        $registry->register($signal, 'f');

        $entries = $registry->snapshot();
        self::assertSame('3.14', $entries[0]->value);
    }

    #[Test]
    public function formatValueShortString(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal('hello');
        $registry->register($signal, 's');

        $entries = $registry->snapshot();
        self::assertSame('"hello"', $entries[0]->value);
    }

    #[Test]
    public function formatValueLongStringTruncatesAt37Plus3Dots(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(str_repeat('x', 41));
        $registry->register($signal, 's');

        $entries = $registry->snapshot();
        self::assertSame('"' . str_repeat('x', 37) . '..."', $entries[0]->value);
    }

    #[Test]
    public function formatValueStringExactly40CharsIsNotTruncated(): void
    {
        $registry = new SignalRegistry();

        $fortyChars = str_repeat('a', 40);
        $signal = new Signal($fortyChars);
        $registry->register($signal, 's');

        $entries = $registry->snapshot();
        self::assertSame('"' . $fortyChars . '"', $entries[0]->value);
    }

    #[Test]
    public function formatValueArray(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal([1, 2, 3]);
        $registry->register($signal, 'a');

        $entries = $registry->snapshot();
        self::assertSame('array(3)', $entries[0]->value);
    }

    #[Test]
    public function formatValueEmptyArray(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal([]);
        $registry->register($signal, 'a');

        $entries = $registry->snapshot();
        self::assertSame('array(0)', $entries[0]->value);
    }

    #[Test]
    public function formatValueNamespacedObject(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(new Signal(0));
        $registry->register($signal, 'o');

        $entries = $registry->snapshot();
        self::assertSame('Signal{}', $entries[0]->value);
    }

    #[Test]
    public function formatValueRootNamespaceObject(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(new \stdClass());
        $registry->register($signal, 'o');

        $entries = $registry->snapshot();
        self::assertSame('stdClass{}', $entries[0]->value);
    }

    #[Test]
    public function countReturnsNumberOfRegisteredSignals(): void
    {
        $registry = new SignalRegistry();

        self::assertSame(0, $registry->count());

        $a = new Signal(1);
        $registry->register($a, 'a');

        $b = new Signal(2);
        $registry->register($b, 'b');

        self::assertSame(2, $registry->count());
    }

    #[Test]
    public function snapshotReflectsSubscriberCount(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(0);
        $registry->register($signal, 'counter');

        $signal->subscribe(static function (): void {
        });
        $signal->subscribe(static function (): void {
        });

        $entries = $registry->snapshot();
        self::assertSame(2, $entries[0]->subscriberCount);
    }

    #[Test]
    public function snapshotReflectsDisposedState(): void
    {
        $registry = new SignalRegistry();

        $signal = new Signal(0);
        $registry->register($signal, 'gone');
        $signal->dispose();

        $entries = $registry->snapshot();
        self::assertTrue($entries[0]->isDisposed);
    }

    #[Test]
    public function freshRegistryHasEmptySnapshot(): void
    {
        $registry = new SignalRegistry();

        self::assertSame([], $registry->snapshot());
    }

    #[Test]
    public function weakMapDropsEntryWhenSignalGoesOutOfScope(): void
    {
        $registry = new SignalRegistry();

        $this->registerEphemeralSignal($registry);

        gc_collect_cycles();

        $entries = $registry->snapshot();
        self::assertSame([], $entries);
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

    private function registerEphemeralSignal(SignalRegistry $registry): void
    {
        $signal = new Signal('temporary');
        $registry->register($signal, 'ephemeral');
    }
}
