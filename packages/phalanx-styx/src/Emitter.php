<?php

declare(strict_types=1);

namespace Phalanx\Styx;

use Closure;
use Generator;
use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use Phalanx\Scope\CoroutineScopeRegistry;
use Phalanx\Scope\Stream\StreamContext;
use Phalanx\Scope\Stream\StreamSource;
use Phalanx\Scope\Stream\Streamable;
use Phalanx\Styx\Terminal\Collect;
use Phalanx\Styx\Terminal\Drain;
use Phalanx\Styx\Terminal\First;
use Phalanx\Styx\Terminal\Reduce;
use Throwable;

/**
 * @implements StreamSource<mixed>
 */
final class Emitter implements StreamSource
{
    use Streamable;

    /** @var Closure(Channel, StreamContext): void */
    private readonly Closure $setup;

    private function __construct(Closure $setup)
    {
        $this->setup = $setup;
        $this->initStreamState();
    }

    /** @param Closure(Channel, StreamContext): void $producer */
    public static function produce(Closure $producer): self
    {
        return new self(static function (Channel $ch, StreamContext $ctx) use ($producer): void {
            self::spawn(static function () use ($producer, $ch, $ctx): void {
                try {
                    $producer($ch, $ctx);
                } catch (Throwable $e) {
                    $ch->error($e);
                    return;
                }
                $ch->complete();
            });
        });
    }

    public static function interval(float $seconds): self
    {
        return new self(static function (Channel $ch, StreamContext $ctx) use ($seconds): void {
            $tick = 0;
            $ms = max(1, (int) round($seconds * 1000));
            $timerId = self::startTick($ms, static function () use ($ch, &$tick): void {
                $ch->emit(++$tick);
            });

            if ($timerId === null) {
                $ch->complete();
                return;
            }

            $ctx->onDispose(static function () use ($timerId, $ch): void {
                Timer::clear($timerId);
                $ch->complete();
            });
        });
    }

    public function __invoke(StreamContext $context): Generator
    {
        $channel = new Channel();
        ($this->setup)($channel, $context);

        $this->fireOnStart($context);

        try {
            foreach ($channel->consume() as $value) {
                $context->throwIfCancelled();
                $this->fireOnEach($value, $context);
                yield $value;
            }
            $this->fireOnComplete($context);
        } catch (Throwable $e) {
            $this->fireOnError($e, $context);
            throw $e;
        } finally {
            // Closing here signals any still-running upstream producer to stop.
            // Without this, an early `break` (e.g. take, first) would leave the
            // producer coroutine suspended on a push to a buffer the consumer
            // has abandoned. complete() is idempotent, so producers that
            // already finished naturally are unaffected.
            $channel->complete();
            $this->fireOnDispose($context);
        }
    }

    /** @param Closure(mixed): mixed $fn */
    public function map(Closure $fn): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $fn): void {
            self::spawn(static function () use ($prev, $ch, $ctx, $fn): void {
                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $ch->emit($fn($value));
                    }
                    $ch->complete();
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            });
        });
    }

    /** @param Closure(mixed): bool $predicate */
    public function filter(Closure $predicate): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $predicate): void {
            self::spawn(static function () use ($prev, $ch, $ctx, $predicate): void {
                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        if ($predicate($value)) {
                            $ch->emit($value);
                        }
                    }
                    $ch->complete();
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            });
        });
    }

    public function take(int $n): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $n): void {
            self::spawn(static function () use ($prev, $ch, $ctx, $n): void {
                try {
                    $count = 0;
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $ch->emit($value);
                        if (++$count >= $n) {
                            break;
                        }
                    }
                    $ch->complete();
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            });
        });
    }

    public function throttle(float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $seconds): void {
            self::spawn(static function () use ($prev, $ch, $ctx, $seconds): void {
                $lastEmitNs = 0.0;
                $intervalNs = $seconds * 1e9;

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $now = (float) hrtime(true);
                        if (($now - $lastEmitNs) >= $intervalNs) {
                            $ch->emit($value);
                            $lastEmitNs = $now;
                        }
                    }
                    $ch->complete();
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            });
        });
    }

    public function debounce(float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $seconds): void {
            $ms = max(1, (int) round($seconds * 1000));

            self::spawn(static function () use ($prev, $ch, $ctx, $ms): void {
                /** @var int|null $timerId */
                $timerId = null;
                $latest = null;
                $hasLatest = false;

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();

                        if ($timerId !== null) {
                            Timer::clear($timerId);
                        }

                        $latest = $value;
                        $hasLatest = true;

                        $timerId = self::startAfter($ms, static function () use ($ch, &$latest, &$hasLatest): void {
                            if ($hasLatest) {
                                $ch->emit($latest);
                                $hasLatest = false;
                            }
                        });
                    }

                    if ($hasLatest) {
                        $ch->emit($latest);
                    }

                    if ($timerId !== null) {
                        Timer::clear($timerId);
                    }

                    $ch->complete();
                } catch (Throwable $e) {
                    if ($timerId !== null) {
                        Timer::clear($timerId);
                    }
                    $ch->error($e);
                }
            });
        });
    }

    public function bufferWindow(int $count, float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $count, $seconds): void {
            $ms = max(1, (int) round($seconds * 1000));

            self::spawn(static function () use ($prev, $ch, $ctx, $count, $ms): void {
                /** @var list<mixed> $buffer */
                $buffer = [];
                /** @var int|null $timerId */
                $timerId = null;

                $flush = static function () use ($ch, &$buffer, &$timerId): void {
                    if ($buffer !== []) {
                        $ch->emit($buffer);
                        $buffer = [];
                    }
                    if ($timerId !== null) {
                        Timer::clear($timerId);
                        $timerId = null;
                    }
                };

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $buffer[] = $value;

                        if ($timerId === null) {
                            $timerId = self::startAfter($ms, static function () use ($flush): void {
                                $flush();
                            });
                        }

                        if (count($buffer) >= $count) {
                            $flush();
                        }
                    }

                    $flush();
                    $ch->complete();
                } catch (Throwable $e) {
                    if ($timerId !== null) {
                        Timer::clear($timerId);
                    }
                    $ch->error($e);
                }
            });
        });
    }

    public function merge(self ...$others): self
    {
        $sources = [$this, ...$others];

        return new self(static function (Channel $ch, StreamContext $ctx) use ($sources): void {
            $remaining = count($sources);
            $failed = false;

            foreach ($sources as $source) {
                self::spawn(static function () use ($source, $ch, $ctx, &$remaining, &$failed): void {
                    try {
                        foreach ($source($ctx) as $value) {
                            $ctx->throwIfCancelled();
                            if ($failed) {
                                return;
                            }
                            $ch->emit($value);
                        }
                    } catch (Throwable $e) {
                        if (!$failed) {
                            $failed = true;
                            $ch->error($e);
                        }
                        return;
                    }

                    $remaining--;
                    if ($remaining <= 0 && !$failed) {
                        $ch->complete();
                    }
                });
            }
        });
    }

    public function distinct(): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev): void {
            self::spawn(static function () use ($prev, $ch, $ctx): void {
                $hasLast = false;
                $lastValue = null;

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        if (!$hasLast || $value !== $lastValue) {
                            $ch->emit($value);
                            $lastValue = $value;
                            $hasLast = true;
                        }
                    }
                    $ch->complete();
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            });
        });
    }

    /** @param Closure(mixed): mixed $keyFn */
    public function distinctBy(Closure $keyFn): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $keyFn): void {
            self::spawn(static function () use ($prev, $ch, $ctx, $keyFn): void {
                $hasLastKey = false;
                $lastKey = null;

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $key = $keyFn($value);
                        if (!$hasLastKey || $key !== $lastKey) {
                            $ch->emit($value);
                            $lastKey = $key;
                            $hasLastKey = true;
                        }
                    }
                    $ch->complete();
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            });
        });
    }

    public function sample(float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $seconds): void {
            $ms = max(1, (int) round($seconds * 1000));
            $latest = null;
            $hasLatest = false;

            $timerId = self::startTick($ms, static function () use ($ch, &$latest, &$hasLatest): void {
                if ($hasLatest) { // @phpstan-ignore if.alwaysFalse (mutated by sibling spawn via &reference)
                    $ch->emit($latest);
                    $hasLatest = false;
                }
            });

            if ($timerId === null) {
                $ch->complete();
                return;
            }

            $ctx->onDispose(static function () use ($timerId): void {
                Timer::clear($timerId);
            });

            self::spawn(static function () use ($prev, $ch, $ctx, &$latest, &$hasLatest): void {
                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $latest = $value;
                        $hasLatest = true;
                    }
                    $ch->complete();
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            });
        });
    }

    public function toArray(): Collect
    {
        return new Collect($this);
    }

    /** @param Closure(mixed, mixed): mixed $fn */
    public function reduce(Closure $fn, mixed $initial = null): Reduce
    {
        return new Reduce($this, $fn, $initial);
    }

    public function first(): First
    {
        return new First($this);
    }

    public function consume(): Drain
    {
        return new Drain($this);
    }

    private static function spawn(Closure $body): void
    {
        $parentScope = CoroutineScopeRegistry::current();
        Coroutine::create(static function () use ($parentScope, $body): void {
            if ($parentScope !== null) {
                CoroutineScopeRegistry::install($parentScope);
            }
            try {
                $body();
            } finally {
                CoroutineScopeRegistry::clear();
            }
        });
    }

    private static function startAfter(int $ms, Closure $callback): ?int
    {
        $result = Timer::after($ms, $callback);
        return is_int($result) ? $result : null;
    }

    private static function startTick(int $ms, Closure $callback): ?int
    {
        $result = Timer::tick($ms, $callback);
        return is_int($result) ? $result : null;
    }
}
