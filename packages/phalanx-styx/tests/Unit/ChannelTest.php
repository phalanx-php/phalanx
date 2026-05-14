<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Unit;

use Phalanx\Pool\BorrowedValue;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class ChannelTest extends PhalanxTestCase
{
    #[Test]
    public function bufferFillsAndDrains(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel(4);

            $scope->go(static function () use ($channel): void {
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
    public function emitRejectsBorrowedValues(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Borrowed values cannot cross Styx channel boundaries');

        $this->scope->run(static function (): void {
            $channel = new Channel();
            $borrowed = new class implements BorrowedValue {
            };

            $channel->emit($borrowed);
        });
    }

    #[Test]
    public function tryEmitRejectsBorrowedValuesInsideArrays(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Borrowed values cannot cross Styx channel boundaries');

        $this->scope->run(static function (): void {
            $channel = new Channel();
            $borrowed = new class implements BorrowedValue {
            };

            $channel->tryEmit(['event' => $borrowed]);
        });
    }

    #[Test]
    public function completeEndsConsumer(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel();

            $scope->go(static function () use ($channel): void {
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
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel();
            $exception = new RuntimeException('test error');

            $scope->go(static function () use ($channel, $exception): void {
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
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel();

            $scope->go(static function () use ($channel): void {
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
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel();

            $scope->go(static function () use ($channel): void {
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
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel();

            $scope->go(static function () use ($channel): void {
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
        $this->scope->run(static function (): void {
            $channel = new Channel();
            self::assertTrue($channel->isOpen);

            $channel->complete();
            self::assertFalse($channel->isOpen);
        });
    }

    #[Test]
    public function isOpenAfterError(): void
    {
        $this->scope->run(static function (): void {
            $channel = new Channel();
            self::assertTrue($channel->isOpen);

            $channel->error(new RuntimeException('fail'));
            self::assertFalse($channel->isOpen);
        });
    }

    #[Test]
    public function backpressurePauseFires(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            $channel = new Channel(2);
            $pressureLog = [];

            $channel->withPressure(static function (bool $pause) use (&$pressureLog): void {
                $pressureLog[] = $pause ? 'pause' : 'resume';
            });

            $scope->go(static function () use ($channel): void {
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
        $this->scope->run(static function (): void {
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
        $this->scope->run(static function (): void {
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
        $this->scope->run(static function (): void {
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
        $this->scope->run(static function (): void {
            $channel = new Channel();
            $channel->complete();

            self::assertFalse($channel->tryEmit('nope'));
        });
    }

    #[Test]
    public function tryEmitReturnsFalseAfterError(): void
    {
        $this->scope->run(static function (): void {
            $channel = new Channel();
            $channel->error(new RuntimeException('broken'));

            self::assertFalse($channel->tryEmit('nope'));
        });
    }

    #[Test]
    public function tryEmitMultiArgStoresAsTuple(): void
    {
        $this->scope->run(static function (): void {
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
        $this->scope->run(static function (): void {
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
