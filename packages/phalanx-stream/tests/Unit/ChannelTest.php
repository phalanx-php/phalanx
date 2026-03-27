<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Unit;

use Phalanx\Stream\Channel;
use PHPUnit\Framework\TestCase;

use function React\Async\async;
use function React\Async\await;

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
}
