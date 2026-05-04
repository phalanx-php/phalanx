<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Unit;

use OpenSwoole\Coroutine;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Tests\Support\AsyncTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ChannelTest extends AsyncTestCase
{
    #[Test]
    public function bufferFillsAndDrains(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel(4);

            Coroutine::create(static function () use ($channel): void {
                $channel->emit('a');
                $channel->emit('b');
                $channel->emit('c');
                $channel->complete();
            });

            $items = [];
            foreach ($channel->consume() as $value) {
                $items[] = $value;
            }

            self::assertSame(['a', 'b', 'c'], $items);
        });
    }

    #[Test]
    public function completeEndsConsumer(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();

            Coroutine::create(static function () use ($channel): void {
                $channel->emit(1);
                $channel->emit(2);
                $channel->complete();
            });

            $items = iterator_to_array($channel->consume());
            self::assertSame([1, 2], $items);
        });
    }

    #[Test]
    public function errorThrowsInConsumer(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();
            $exception = new RuntimeException('test error');

            Coroutine::create(static function () use ($channel, $exception): void {
                $channel->emit('before');
                $channel->error($exception);
            });

            $threw = null;
            try {
                foreach ($channel->consume() as $_) {
                }
            } catch (RuntimeException $e) {
                $threw = $e;
            }

            self::assertNotNull($threw);
            self::assertSame('test error', $threw->getMessage());
        });
    }

    #[Test]
    public function doubleCompleteIsIdempotent(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();

            Coroutine::create(static function () use ($channel): void {
                $channel->emit('x');
                $channel->complete();
                $channel->complete();
            });

            $items = iterator_to_array($channel->consume());
            self::assertSame(['x'], $items);
        });
    }

    #[Test]
    public function multiArgEmitStoresAsTuple(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();

            Coroutine::create(static function () use ($channel): void {
                $channel->emit('hello', 42, true);
                $channel->complete();
            });

            $items = iterator_to_array($channel->consume());
            self::assertSame([['hello', 42, true]], $items);
        });
    }

    #[Test]
    public function singleArgUnwrapped(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();

            Coroutine::create(static function () use ($channel): void {
                $channel->emit('solo');
                $channel->complete();
            });

            $items = iterator_to_array($channel->consume());
            self::assertSame(['solo'], $items);
        });
    }

    #[Test]
    public function isOpenProperty(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();
            self::assertTrue($channel->isOpen);

            $channel->complete();
            self::assertFalse($channel->isOpen);
        });
    }

    #[Test]
    public function isOpenAfterError(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();
            self::assertTrue($channel->isOpen);

            $channel->error(new RuntimeException('fail'));
            self::assertFalse($channel->isOpen);
        });
    }

    #[Test]
    public function backpressurePauseFires(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel(2);
            $pressureLog = [];

            $channel->withPressure(static function (bool $pause) use (&$pressureLog): void {
                $pressureLog[] = $pause ? 'pause' : 'resume';
            });

            Coroutine::create(static function () use ($channel): void {
                $channel->emit('a');
                $channel->emit('b');
                $channel->emit('c');
                $channel->complete();
            });

            $items = iterator_to_array($channel->consume());
            self::assertSame(['a', 'b', 'c'], $items);
            self::assertContains('pause', $pressureLog);
        });
    }

    #[Test]
    public function emitAfterCompleteIsIgnored(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();
            $channel->complete();
            $channel->emit('ignored');

            $items = iterator_to_array($channel->consume());
            self::assertSame([], $items);
        });
    }

    #[Test]
    public function tryEmitReturnsTrueWhenBufferHasRoom(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel(bufferSize: 4);

            self::assertTrue($channel->tryEmit('a'));
            self::assertTrue($channel->tryEmit('b'));
            self::assertTrue($channel->tryEmit('c'));

            $channel->complete();

            $items = iterator_to_array($channel->consume());
            self::assertSame(['a', 'b', 'c'], $items);
        });
    }

    #[Test]
    public function tryEmitReturnsFalseWhenBufferIsFull(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel(bufferSize: 2);

            self::assertTrue($channel->tryEmit('a'));
            self::assertTrue($channel->tryEmit('b'));
            self::assertFalse($channel->tryEmit('c'));

            $channel->complete();

            $items = iterator_to_array($channel->consume());
            self::assertSame(['a', 'b'], $items);
        });
    }

    #[Test]
    public function tryEmitReturnsFalseAfterChannelClosed(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();
            $channel->complete();

            self::assertFalse($channel->tryEmit('nope'));
        });
    }

    #[Test]
    public function tryEmitReturnsFalseAfterError(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel();
            $channel->error(new RuntimeException('broken'));

            self::assertFalse($channel->tryEmit('nope'));
        });
    }

    #[Test]
    public function tryEmitMultiArgStoresAsTuple(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel(bufferSize: 4);

            $channel->tryEmit('hello', 42, true);
            $channel->complete();

            $items = iterator_to_array($channel->consume());
            self::assertSame([['hello', 42, true]], $items);
        });
    }

    #[Test]
    public function tryEmitDoesNotSuspend(): void
    {
        $this->runAsync(function (): void {
            $channel = new Channel(bufferSize: 1);

            self::assertTrue($channel->tryEmit('a'));

            $start = hrtime(true);
            self::assertFalse($channel->tryEmit('b'));
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            self::assertLessThan(5.0, $elapsedMs);

            $channel->complete();
        });
    }
}
