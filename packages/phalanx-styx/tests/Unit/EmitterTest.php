<?php

declare(strict_types=1);

namespace Phalanx\Styx\Tests\Unit;

use Closure;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use Phalanx\Styx\Tests\Support\AsyncTestCase;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

final class EmitterTest extends AsyncTestCase
{
    private static function makeContext(): StreamContext
    {
        return new class () implements StreamContext {
            public RuntimeContext $runtime {
                get => throw new RuntimeException('Test stream context has no runtime.');
            }

            public function call(Closure $fn, ?WaitReason $waitReason = null): mixed
            {
                return $fn();
            }

            public function throwIfCancelled(): void
            {
            }

            public function onDispose(Closure $callback): void
            {
            }

            public function service(string $type): object
            {
                throw new RuntimeException("Test stream context has no service '{$type}'.");
            }

            public function attribute(string $key, mixed $default = null): mixed
            {
                return $default;
            }

            public function withAttribute(string $key, mixed $value): Scope
            {
                return $this;
            }

            public function trace(): Trace
            {
                return new Trace();
            }
        };
    }

    #[Test]
    public function produceExposesChannel(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('produced-1');
                $ch->emit('produced-2');
            });

            $items = iterator_to_array($emitter(self::makeContext()));

            self::assertSame(['produced-1', 'produced-2'], $items);
        });
    }

    #[Test]
    public function mapOperator(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
            });

            $doubled = $emitter->map(static fn(int $v): int => $v * 2);

            $items = iterator_to_array($doubled(self::makeContext()));

            self::assertSame([2, 4, 6], $items);
        });
    }

    #[Test]
    public function filterOperator(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
                $ch->emit(4);
            });

            $evens = $emitter->filter(static fn(int $v): bool => $v % 2 === 0);

            $items = iterator_to_array($evens(self::makeContext()));

            self::assertSame([2, 4], $items);
        });
    }

    #[Test]
    public function takeOperator(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                for ($i = 1; $i <= 100; $i++) {
                    $ch->emit($i);
                }
            });

            $first3 = $emitter->take(3);

            $items = iterator_to_array($first3(self::makeContext()));

            self::assertSame([1, 2, 3], $items);
        });
    }

    #[Test]
    public function distinctOperator(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(2);
                $ch->emit(1);
            });

            $distinct = $emitter->distinct();

            $items = iterator_to_array($distinct(self::makeContext()));

            self::assertSame([1, 2, 1], $items);
        });
    }

    #[Test]
    public function distinctByOperator(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(['name' => 'Alice', 'age' => 30]);
                $ch->emit(['name' => 'Alice', 'age' => 31]);
                $ch->emit(['name' => 'Bob', 'age' => 25]);
            });

            $distinct = $emitter->distinctBy(static fn(array $v): string => $v['name']);

            $items = iterator_to_array($distinct(self::makeContext()));

            self::assertSame([
                ['name' => 'Alice', 'age' => 30],
                ['name' => 'Bob', 'age' => 25],
            ], $items);
        });
    }

    #[Test]
    public function mergeInterleaves(): void
    {
        $this->runAsync(static function (): void {
            $a = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('a1');
                $ch->emit('a2');
            });

            $b = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('b1');
                $ch->emit('b2');
            });

            $merged = $a->merge($b);

            $items = iterator_to_array($merged(self::makeContext()));

            sort($items);
            self::assertSame(['a1', 'a2', 'b1', 'b2'], $items);
        });
    }

    #[Test]
    public function lifecycleHooksFire(): void
    {
        $this->runAsync(static function (): void {
            $log = [];

            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('x');
            })
                ->onStart(static function () use (&$log): void {
                    $log[] = 'start';
                })
                ->onEach(static function (mixed $v) use (&$log): void {
                    $log[] = "each:{$v}";
                })
                ->onComplete(static function () use (&$log): void {
                    $log[] = 'complete';
                })
                ->onDispose(static function () use (&$log): void {
                    $log[] = 'dispose';
                });

            iterator_to_array($emitter(self::makeContext()));

            self::assertSame(['start', 'each:x', 'complete', 'dispose'], $log);
        });
    }

    #[Test]
    public function errorHookFires(): void
    {
        $this->runAsync(static function (): void {
            $errorLog = [];

            $emitter = Emitter::produce(static function (): void {
                throw new RuntimeException('boom');
            })
                ->onError(static function (Throwable $e) use (&$errorLog): void {
                    $errorLog[] = $e->getMessage();
                });

            try {
                iterator_to_array($emitter(self::makeContext()));
            } catch (RuntimeException) {
            }

            self::assertSame(['boom'], $errorLog);
        });
    }

    #[Test]
    public function toArrayTerminal(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
            });

            $collect = $emitter->toArray();

            self::assertSame([1, 2], $collect(self::makeContext()));
        });
    }

    #[Test]
    public function reduceTerminal(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
            });

            $sum = $emitter->reduce(static fn(int $acc, int $v): int => $acc + $v, 0);

            self::assertSame(6, $sum(self::makeContext()));
        });
    }

    #[Test]
    public function firstTerminal(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('first');
                $ch->emit('second');
            });

            $first = $emitter->first();

            self::assertSame('first', $first(self::makeContext()));
        });
    }

    #[Test]
    public function consumeTerminal(): void
    {
        $this->runAsync(static function (): void {
            $sideEffects = [];

            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit('a');
                $ch->emit('b');
            })->onEach(static function (mixed $v) use (&$sideEffects): void {
                $sideEffects[] = $v;
            });

            $drain = $emitter->consume();
            $drain(self::makeContext());

            self::assertSame(['a', 'b'], $sideEffects);
        });
    }

    #[Test]
    public function bufferWindowByCount(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                for ($i = 1; $i <= 5; $i++) {
                    $ch->emit($i);
                }
            });

            $buffered = $emitter->bufferWindow(2, 10.0);

            $items = iterator_to_array($buffered(self::makeContext()));

            self::assertSame([[1, 2], [3, 4], [5]], $items);
        });
    }

    #[Test]
    public function throttleDropsExtraItems(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (Channel $ch): void {
                $ch->emit(1);
                $ch->emit(2);
                $ch->emit(3);
            });

            $throttled = $emitter->throttle(100.0);

            $items = iterator_to_array($throttled(self::makeContext()));

            self::assertSame([1], $items);
        });
    }

    #[Test]
    public function emptyStreamCompletes(): void
    {
        $this->runAsync(static function (): void {
            $emitter = Emitter::produce(static function (): void {
            });

            $items = iterator_to_array($emitter(self::makeContext()));

            self::assertSame([], $items);
        });
    }
}
