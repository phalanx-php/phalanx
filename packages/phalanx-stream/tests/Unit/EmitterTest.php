<?php

declare(strict_types=1);

namespace Phalanx\Stream\Tests\Unit;

use Closure;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;
use Phalanx\Stream\Tests\Support\AsyncTestCase;
use Evenement\EventEmitter;
use PHPUnit\Framework\Attributes\Test;
use React\EventLoop\Loop;
use React\Stream\ThroughStream;

use function React\Async\async;

final class EmitterTest extends AsyncTestCase
{
    private function makeContext(): StreamContext
    {
        return new class () implements StreamContext {
            /** @var list<Closure> */
            private array $disposeCallbacks = [];

            private bool $cancelled = false;

            public function throwIfCancelled(): void
            {
                if ($this->cancelled) {
                    throw new \RuntimeException('Cancelled');
                }
            }

            public function onDispose(Closure $callback): void
            {
                $this->disposeCallbacks[] = $callback;
            }

            public function cancel(): void
            {
                $this->cancelled = true;
            }

            public function dispose(): void
            {
                foreach ($this->disposeCallbacks as $cb) {
                    $cb();
                }
                $this->disposeCallbacks = [];
            }
        };
    }

    #[Test]
    public function testStreamFactoryWiresEvents(): void
    {
        $this->runAsync(function (): void {
            $through = new ThroughStream();
            $emitter = Emitter::stream($through);
            $ctx = $this->makeContext();

            Loop::futureTick(static function () use ($through): void {
                $through->write('hello');
                $through->write('world');
                $through->end();
            });

            $items = [];
            foreach ($emitter($ctx) as $value) {
                $items[] = $value;
            }

            $this->assertSame(['hello', 'world'], $items);
        });
    }

    #[Test]
    public function testStreamFactoryWithCallable(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::stream(static function (): ThroughStream {
                $stream = new ThroughStream();

                Loop::futureTick(static function () use ($stream): void {
                    $stream->write('lazy');
                    $stream->end();
                });

                return $stream;
            });

            $ctx = $this->makeContext();
            $items = iterator_to_array($emitter($ctx));

            $this->assertSame(['lazy'], $items);
        });
    }

    #[Test]
    public function testListenFactoryWithEventName(): void
    {
        $this->runAsync(function (): void {
            $ee = new EventEmitter();
            $emitter = Emitter::listen('message', $ee);
            $ctx = $this->makeContext();

            Loop::futureTick(static function () use ($ee): void {
                $ee->emit('message', ['msg1']);
                $ee->emit('message', ['msg2']);
                $ee->emit('close');
            });

            $items = iterator_to_array($emitter($ctx));
            $this->assertSame(['msg1', 'msg2'], $items);
        });
    }

    #[Test]
    public function testListenMultiArgEvents(): void
    {
        $this->runAsync(function (): void {
            $ee = new EventEmitter();
            $emitter = Emitter::listen('data', $ee);
            $ctx = $this->makeContext();

            Loop::futureTick(static function () use ($ee): void {
                $ee->emit('data', ['key', 'value', 42]);
                $ee->emit('close');
            });

            $items = iterator_to_array($emitter($ctx));
            $this->assertSame([['key', 'value', 42]], $items);
        });
    }

    #[Test]
    public function testProduceExposesChannel(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('produced-1');
                $ch->emit('produced-2');
            });

            $ctx = $this->makeContext();
            $items = iterator_to_array($emitter($ctx));

            $this->assertSame(['produced-1', 'produced-2'], $items);
        });
    }

    #[Test]
    public function testMapOperator(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
            });

            $doubled = $emitter->map(static fn(int $v): int => $v * 2);

            $ctx = $this->makeContext();
            $items = iterator_to_array($doubled($ctx));

            $this->assertSame([2, 4, 6], $items);
        });
    }

    #[Test]
    public function testFilterOperator(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
                $ch->emit(4);
            });

            $evens = $emitter->filter(static fn(int $v): bool => $v % 2 === 0);

            $ctx = $this->makeContext();
            $items = iterator_to_array($evens($ctx));

            $this->assertSame([2, 4], $items);
        });
    }

    #[Test]
    public function testTakeOperator(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                for ($i = 1; $i <= 100; $i++) {
                    $ch->emit($i);
                }
            });

            $first3 = $emitter->take(3);

            $ctx = $this->makeContext();
            $items = iterator_to_array($first3($ctx));

            $this->assertSame([1, 2, 3], $items);
        });
    }

    #[Test]
    public function testDistinctOperator(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(2);
                $ch->emit(1);
            });

            $distinct = $emitter->distinct();

            $ctx = $this->makeContext();
            $items = iterator_to_array($distinct($ctx));

            $this->assertSame([1, 2, 1], $items);
        });
    }

    #[Test]
    public function testDistinctByOperator(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(['name' => 'Alice', 'age' => 30]);
                $ch->emit(['name' => 'Alice', 'age' => 31]);
                $ch->emit(['name' => 'Bob', 'age' => 25]);
            });

            $distinct = $emitter->distinctBy(static fn(array $v): string => $v['name']);

            $ctx = $this->makeContext();
            $items = iterator_to_array($distinct($ctx));

            $this->assertSame([
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ], $items);
        });
    }

    #[Test]
    public function testMergeInterleaves(): void
    {
        $this->runAsync(function (): void {
            $a = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('a1');
                $ch->emit('a2');
            });

            $b = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('b1');
                $ch->emit('b2');
            });

            $merged = $a->merge($b);

            $ctx = $this->makeContext();
            $items = iterator_to_array($merged($ctx));

            sort($items);
            $this->assertSame(['a1', 'a2', 'b1', 'b2'], $items);
        });
    }

    #[Test]
    public function testLifecycleHooksFire(): void
    {
        $this->runAsync(function (): void {
            $log = [];

            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('x');
            })
                ->onStart(static function () use (&$log): void { $log[] = 'start'; })
                ->onEach(static function ($v) use (&$log): void { $log[] = "each:$v"; })
                ->onComplete(static function () use (&$log): void { $log[] = 'complete'; })
                ->onDispose(static function () use (&$log): void { $log[] = 'dispose'; });

            $ctx = $this->makeContext();
            iterator_to_array($emitter($ctx));

            $this->assertSame(['start', 'each:x', 'complete', 'dispose'], $log);
        });
    }

    #[Test]
    public function testErrorHookFires(): void
    {
        $this->runAsync(function (): void {
            $errorLog = [];

            $emitter = Emitter::produce(static function (): void {
                throw new \RuntimeException('boom');
            })
                ->onError(static function (\Throwable $e) use (&$errorLog): void {
                    $errorLog[] = $e->getMessage();
                });

            $ctx = $this->makeContext();

            try {
                iterator_to_array($emitter($ctx));
            } catch (\RuntimeException) {
            }

            $this->assertSame(['boom'], $errorLog);
        });
    }

    #[Test]
    public function testToArrayTerminal(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
            });

            $collect = $emitter->toArray();

            $ctx = $this->makeContext();
            $result = $collect($ctx);

            $this->assertSame([1, 2], $result);
        });
    }

    #[Test]
    public function testReduceTerminal(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
            });

            $sum = $emitter->reduce(static fn(int $acc, int $v): int => $acc + $v, 0);

            $ctx = $this->makeContext();
            $result = $sum($ctx);

            $this->assertSame(6, $result);
        });
    }

    #[Test]
    public function testFirstTerminal(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('first');
                $ch->emit('second');
            });

            $first = $emitter->first();

            $ctx = $this->makeContext();
            $result = $first($ctx);

            $this->assertSame('first', $result);
        });
    }

    #[Test]
    public function testConsumeTerminal(): void
    {
        $this->runAsync(function (): void {
            $sideEffects = [];

            $emitter = Emitter::produce(static function (Channel $ch) use (&$sideEffects): void {
                $ch->emit('a');
                $ch->emit('b');
            })->onEach(static function ($v) use (&$sideEffects): void {
                $sideEffects[] = $v;
            });

            $drain = $emitter->consume();
            $ctx = $this->makeContext();
            $result = $drain($ctx);

            $this->assertNull($result);
            $this->assertSame(['a', 'b'], $sideEffects);
        });
    }

    #[Test]
    public function testBufferWindowByCount(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                for ($i = 1; $i <= 5; $i++) {
                    $ch->emit($i);
                }
            });

            $buffered = $emitter->bufferWindow(2, 10.0);

            $ctx = $this->makeContext();
            $items = iterator_to_array($buffered($ctx));

            $this->assertSame([[1, 2], [3, 4], [5]], $items);
        });
    }

    #[Test]
    public function testThrottleDropsExtraItems(): void
    {
        $this->runAsync(function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
            });

            $throttled = $emitter->throttle(100.0);

            $ctx = $this->makeContext();
            $items = iterator_to_array($throttled($ctx));

            $this->assertSame([1], $items);
        });
    }
}
