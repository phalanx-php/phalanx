<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Tests\Unit\DevTools;

use Phalanx\Theatron\DevTools\SignalRegistry;
use Phalanx\Theatron\Reactive\Signal;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SignalRegistryTest extends TestCase
{
    #[Test]
    public function register_tracks_signal(): void
    {
        SignalRegistry::enable();
        $signal = new Signal(42);
        SignalRegistry::register($signal, 'counter');

        $snapshot = SignalRegistry::snapshot();

        self::assertCount(1, $snapshot);
        self::assertSame('counter', $snapshot[0]->label);
        self::assertSame('42', $snapshot[0]->value);
        self::assertFalse($snapshot[0]->isDisposed);

        SignalRegistry::disable();
    }

    #[Test]
    public function snapshot_reflects_current_value(): void
    {
        SignalRegistry::enable();
        $signal = new Signal('hello');
        SignalRegistry::register($signal, 'greeting');

        $signal->value = 'world';
        $snapshot = SignalRegistry::snapshot();

        self::assertSame('"world"', $snapshot[0]->value);

        SignalRegistry::disable();
    }

    #[Test]
    public function disposed_signal_shows_disposed(): void
    {
        SignalRegistry::enable();
        $signal = new Signal(10);
        SignalRegistry::register($signal, 'temp');

        $signal->dispose();
        $snapshot = SignalRegistry::snapshot();

        self::assertCount(1, $snapshot);
        self::assertTrue($snapshot[0]->isDisposed);

        SignalRegistry::disable();
    }

    #[Test]
    public function disable_clears_registry(): void
    {
        SignalRegistry::enable();
        $signal = new Signal(1);
        SignalRegistry::register($signal, 'x');

        SignalRegistry::disable();

        self::assertSame([], SignalRegistry::snapshot());
    }

    #[Test]
    public function register_when_disabled_is_noop(): void
    {
        SignalRegistry::disable();

        $signal = new Signal(1);
        SignalRegistry::register($signal, 'x');

        self::assertSame([], SignalRegistry::snapshot());
    }

    #[Test]
    public function subscriber_count_tracked(): void
    {
        SignalRegistry::enable();
        $signal = new Signal(0);
        SignalRegistry::register($signal, 'observed');

        $signal->subscribe(static fn() => null);
        $signal->subscribe(static fn() => null);

        $snapshot = SignalRegistry::snapshot();

        self::assertSame(2, $snapshot[0]->subscriberCount);

        SignalRegistry::disable();
    }

    #[Test]
    public function formats_array_value(): void
    {
        SignalRegistry::enable();
        $signal = new Signal(['a', 'b', 'c']);
        SignalRegistry::register($signal, 'list');

        $snapshot = SignalRegistry::snapshot();

        self::assertSame('array(3)', $snapshot[0]->value);

        SignalRegistry::disable();
    }

    #[Test]
    public function formats_long_string(): void
    {
        SignalRegistry::enable();
        $signal = new Signal(str_repeat('x', 100));
        SignalRegistry::register($signal, 'long');

        $snapshot = SignalRegistry::snapshot();

        self::assertSame('"' . str_repeat('x', 37) . '..."', $snapshot[0]->value);

        SignalRegistry::disable();
    }
}
