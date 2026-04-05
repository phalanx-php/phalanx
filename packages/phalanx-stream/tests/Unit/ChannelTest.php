<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Unit;

use Phalanx\Stream\Channel;
use PHPUnit\Framework\TestCase;

use function React\Async\async;
use function React\Async\await;
use function React\Async\delay;

final class ChannelTest extends TestCase
{
    public function testBufferFillsAndDrains(): void
    {
        $channel = new Channel(4);

        async(static function () use ($channel): void {
            $channel->emit('a');
            $channel->emit('b');
            $channel->emit('c');
            $channel->complete();
        })();

        $items = [];
        foreach ($channel->consume() as $value) {
            $items[] = $value;
        }

        self::assertSame(['a', 'b', 'c'], $items);
    }

    public function testCompleteEndsConsumer(): void
    {
        $channel = new Channel();

        async(static function () use ($channel): void {
            $channel->emit(1);
            $channel->emit(2);
            $channel->complete();
        })();

        $items = iterator_to_array($channel->consume());
        self::assertSame([1, 2], $items);
    }

    public function testErrorThrowsInConsumer(): void
    {
        $channel = new Channel();
        $exception = new \RuntimeException('test error');

        async(static function () use ($channel, $exception): void {
            $channel->emit('before');
            $channel->error($exception);
        })();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');

        $items = [];
        foreach ($channel->consume() as $value) {
            $items[] = $value;
        }
    }

    public function testDoubleCompleteIsIdempotent(): void
    {
        $channel = new Channel();

        async(static function () use ($channel): void {
            $channel->emit('x');
            $channel->complete();
            $channel->complete();
        })();

        $items = iterator_to_array($channel->consume());
        self::assertSame(['x'], $items);
    }

    public function testMultiArgEmitStoresAsTuple(): void
    {
        $channel = new Channel();

        async(static function () use ($channel): void {
            $channel->emit('hello', 42, true);
            $channel->complete();
        })();

        $items = iterator_to_array($channel->consume());
        self::assertSame([['hello', 42, true]], $items);
    }

    public function testSingleArgUnwrapped(): void
    {
        $channel = new Channel();

        async(static function () use ($channel): void {
            $channel->emit('solo');
            $channel->complete();
        })();

        $items = iterator_to_array($channel->consume());
        self::assertSame(['solo'], $items);
    }

    public function testIsOpenProperty(): void
    {
        $channel = new Channel();
        self::assertTrue($channel->isOpen);

        $channel->complete();
        self::assertFalse($channel->isOpen);
    }

    public function testIsOpenAfterError(): void
    {
        $channel = new Channel();
        self::assertTrue($channel->isOpen);

        $channel->error(new \RuntimeException('fail'));
        self::assertFalse($channel->isOpen);
    }

    public function testBackpressureCallbackFires(): void
    {
        $channel = new Channel(2);
        $pressureLog = [];

        $channel->withPressure(static function (bool $pause) use (&$pressureLog): void {
            $pressureLog[] = $pause ? 'pause' : 'resume';
        });

        async(static function () use ($channel): void {
            $channel->emit('a');
            $channel->emit('b');
            $channel->emit('c');
            $channel->complete();
        })();

        $items = iterator_to_array($channel->consume());
        self::assertSame(['a', 'b', 'c'], $items);
        self::assertContains('pause', $pressureLog);
    }

    public function testEmitAfterCompleteIsIgnored(): void
    {
        $channel = new Channel();
        $channel->complete();
        $channel->emit('ignored');

        $items = iterator_to_array($channel->consume());
        self::assertSame([], $items);
    }

    public function test_tryEmit_returns_true_when_buffer_has_room(): void
    {
        $channel = new Channel(bufferSize: 4);

        self::assertTrue($channel->tryEmit('a'));
        self::assertTrue($channel->tryEmit('b'));
        self::assertTrue($channel->tryEmit('c'));

        $channel->complete();

        $items = iterator_to_array($channel->consume());
        self::assertSame(['a', 'b', 'c'], $items);
    }

    public function test_tryEmit_returns_false_when_buffer_is_full(): void
    {
        $channel = new Channel(bufferSize: 2);

        self::assertTrue($channel->tryEmit('a'));
        self::assertTrue($channel->tryEmit('b'));
        self::assertFalse($channel->tryEmit('c'));

        $channel->complete();

        $items = iterator_to_array($channel->consume());
        self::assertSame(['a', 'b'], $items);
    }

    public function test_tryEmit_wakes_waiting_consumer(): void
    {
        $received = [];

        await(async(static function () use (&$received): void {
            $channel = new Channel(bufferSize: 4);

            async(static function () use ($channel, &$received): void {
                foreach ($channel->consume() as $value) {
                    $received[] = $value;
                }
            })();

            // Yield to let the consumer fiber start and suspend waiting for data
            delay(0);

            $channel->tryEmit('hello');
            $channel->complete();

            // Yield to let futureTick wake the consumer and process the value
            delay(0);
        })());

        self::assertSame(['hello'], $received);
    }

    public function test_tryEmit_fires_pressure_callback(): void
    {
        $channel = new Channel(bufferSize: 2);
        $pressureLog = [];

        $channel->withPressure(static function (bool $pause) use (&$pressureLog): void {
            $pressureLog[] = $pause ? 'pause' : 'resume';
        });

        $channel->tryEmit('a');
        $channel->tryEmit('b');

        self::assertContains('pause', $pressureLog);
    }

    public function test_tryEmit_returns_false_after_channel_closed(): void
    {
        $channel = new Channel();
        $channel->complete();

        self::assertFalse($channel->tryEmit('nope'));
    }

    public function test_tryEmit_interleaves_with_emit(): void
    {
        $channel = new Channel(bufferSize: 4);

        async(static function () use ($channel): void {
            $channel->tryEmit('try-1');
            $channel->emit('emit-1');
            $channel->tryEmit('try-2');
            $channel->emit('emit-2');
            $channel->complete();
        })();

        $items = iterator_to_array($channel->consume());
        self::assertSame(['try-1', 'emit-1', 'try-2', 'emit-2'], $items);
    }

    public function test_tryEmit_returns_false_after_error(): void
    {
        $channel = new Channel();
        $channel->error(new \RuntimeException('broken'));

        self::assertFalse($channel->tryEmit('nope'));
    }

    public function test_tryEmit_fires_pressure_callback_exactly_once(): void
    {
        $channel = new Channel(bufferSize: 2);
        $pressureLog = [];

        $channel->withPressure(static function (bool $pause) use (&$pressureLog): void {
            $pressureLog[] = $pause ? 'pause' : 'resume';
        });

        $channel->tryEmit('a');
        $channel->tryEmit('b');

        // Buffer is now full; third tryEmit returns false without firing callback again
        self::assertFalse($channel->tryEmit('c'));

        self::assertSame(['pause'], $pressureLog);
    }

    public function test_tryEmit_multi_arg_stores_as_tuple(): void
    {
        $channel = new Channel(bufferSize: 4);

        $channel->tryEmit('hello', 42, true);
        $channel->complete();

        $items = iterator_to_array($channel->consume());
        self::assertSame([['hello', 42, true]], $items);
    }

    public function test_tryEmit_does_not_suspend(): void
    {
        $channel = new Channel(bufferSize: 1);

        // Fill to capacity
        self::assertTrue($channel->tryEmit('a'));

        // This must return false immediately -- if it suspended, the test would hang
        $start = hrtime(true);
        self::assertFalse($channel->tryEmit('b'));
        $elapsed = (hrtime(true) - $start) / 1_000_000; // milliseconds

        // Should complete in under 1ms -- no event loop tick involved
        self::assertLessThan(1.0, $elapsed);

        $channel->complete();
    }
}
