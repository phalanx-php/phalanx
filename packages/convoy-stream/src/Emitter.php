<?php

declare(strict_types=1);

namespace Convoy\Stream;

use Convoy\Stream\Contract\StreamContext;
use Convoy\Stream\Contract\StreamSource;
use Convoy\Stream\Contract\Streamable;
use Evenement\EventEmitterInterface;
use Generator;
use React\EventLoop\Loop;
use React\Stream\ReadableStreamInterface;

use function React\Async\async;

final class Emitter implements StreamSource
{
    use Streamable {
        onDispose as private traitOnDispose;
    }

    /** @var callable(Channel, StreamContext): void */
    private $setup;

    private function __construct(callable $setup)
    {
        $this->setup = $setup;
        $this->initStreamState();
    }

    public static function stream(ReadableStreamInterface|callable $source): self
    {
        return new self(static function (Channel $ch, StreamContext $ctx) use ($source): void {
            $stream = is_callable($source) ? $source() : $source;

            $ch->withPressure(static function (bool $pause) use ($stream): void {
                $pause ? $stream->pause() : $stream->resume();
            });

            $stream->on('data', static function (mixed $data) use ($ch): void {
                $ch->emit($data);
            });

            $stream->on('end', static function () use ($ch): void {
                $ch->complete();
            });

            $stream->on('error', static function (\Throwable $e) use ($ch): void {
                $ch->error($e);
            });

            $stream->on('close', static function () use ($ch): void {
                $ch->complete();
            });

            $ctx->onDispose(static function () use ($stream): void {
                if ($stream->isReadable()) {
                    $stream->close();
                }
            });
        });
    }

    public static function listen(string $event, EventEmitterInterface|callable $source): self
    {
        return new self(static function (Channel $ch, StreamContext $ctx) use ($event, $source): void {
            $emitter = is_callable($source) ? $source() : $source;

            $emitter->on($event, static function (mixed ...$args) use ($ch): void {
                $ch->emit(...$args);
            });

            $emitter->on('error', static function (\Throwable $e) use ($ch): void {
                $ch->error($e);
            });

            $emitter->on('close', static function () use ($ch): void {
                $ch->complete();
            });

            if (method_exists($emitter, 'close')) {
                $ctx->onDispose(static function () use ($emitter): void {
                    $emitter->close();
                });
            }
        });
    }

    public static function interval(float $seconds): self
    {
        return new self(static function (Channel $ch, StreamContext $ctx) use ($seconds): void {
            $tick = 0;
            $timer = Loop::addPeriodicTimer($seconds, static function () use ($ch, &$tick): void {
                $ch->emit(++$tick);
            });

            $ctx->onDispose(static function () use ($timer, $ch): void {
                Loop::cancelTimer($timer);
                $ch->complete();
            });
        });
    }

    /** @param callable(Channel, StreamContext): void $producer */
    public static function produce(callable $producer): self
    {
        return new self(static function (Channel $ch, StreamContext $ctx) use ($producer): void {
            async(static function () use ($producer, $ch, $ctx): void {
                try {
                    $producer($ch, $ctx);
                } catch (\Throwable $e) {
                    $ch->error($e);
                } finally {
                    $ch->complete();
                }
            })();
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
        } catch (\Throwable $e) {
            $this->fireOnError($e, $context);
            throw $e;
        } finally {
            $this->fireOnDispose($context);
        }
    }

    /** @param callable(mixed): mixed $fn */
    public function map(callable $fn): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $fn): void {
            async(static function () use ($prev, $ch, $ctx, $fn): void {
                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $ch->emit($fn($value));
                    }
                    $ch->complete();
                } catch (\Throwable $e) {
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    /** @param callable(mixed): bool $predicate */
    public function filter(callable $predicate): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $predicate): void {
            async(static function () use ($prev, $ch, $ctx, $predicate): void {
                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        if ($predicate($value)) {
                            $ch->emit($value);
                        }
                    }
                    $ch->complete();
                } catch (\Throwable $e) {
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function take(int $n): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $n): void {
            async(static function () use ($prev, $ch, $ctx, $n): void {
                try {
                    $count = 0;
                    foreach ($prev($ctx) as $value) {
                        if ($count >= $n) {
                            break;
                        }
                        $ctx->throwIfCancelled();
                        $ch->emit($value);
                        $count++;
                    }
                    $ch->complete();
                } catch (\Throwable $e) {
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function throttle(float $seconds): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $seconds): void {
            async(static function () use ($prev, $ch, $ctx, $seconds): void {
                $lastEmit = 0.0;
                $intervalNs = $seconds * 1e9;

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $now = (float) hrtime(true);
                        if (($now - $lastEmit) >= $intervalNs) {
                            $ch->emit($value);
                            $lastEmit = $now;
                        }
                    }
                    $ch->complete();
                } catch (\Throwable $e) {
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function debounce(float $seconds): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $seconds): void {
            async(static function () use ($prev, $ch, $ctx, $seconds): void {
                $timer = null;
                $lastValue = null;
                $hasValue = false;

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();

                        if ($timer !== null) {
                            Loop::cancelTimer($timer);
                        }

                        $lastValue = $value;
                        $hasValue = true;

                        $timer = Loop::addTimer($seconds, static function () use ($ch, &$lastValue, &$hasValue): void {
                            if ($hasValue) {
                                $ch->emit($lastValue);
                                $hasValue = false;
                            }
                        });
                    }

                    if ($hasValue) {
                        $ch->emit($lastValue);
                    }

                    if ($timer !== null) {
                        Loop::cancelTimer($timer);
                    }

                    $ch->complete();
                } catch (\Throwable $e) {
                    if ($timer !== null) {
                        Loop::cancelTimer($timer);
                    }
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function bufferWindow(int $count, float $seconds): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $count, $seconds): void {
            async(static function () use ($prev, $ch, $ctx, $count, $seconds): void {
                $buffer = [];
                $timer = null;

                $flush = static function () use ($ch, &$buffer, &$timer): void {
                    if ($buffer !== []) {
                        $ch->emit($buffer);
                        $buffer = [];
                    }
                    if ($timer !== null) {
                        Loop::cancelTimer($timer);
                        $timer = null;
                    }
                };

                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $buffer[] = $value;

                        if ($timer === null) {
                            $timer = Loop::addTimer($seconds, static function () use ($flush): void {
                                $flush();
                            });
                        }

                        if (count($buffer) >= $count) {
                            $flush();
                        }
                    }

                    $flush();
                    $ch->complete();
                } catch (\Throwable $e) {
                    if ($timer !== null) {
                        Loop::cancelTimer($timer);
                    }
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function merge(self ...$others): self
    {
        $sources = [$this, ...$others];

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($sources): void {
            $remaining = count($sources);

            foreach ($sources as $source) {
                async(static function () use ($source, $ch, $ctx, &$remaining): void {
                    try {
                        foreach ($source($ctx) as $value) {
                            $ctx->throwIfCancelled();
                            $ch->emit($value);
                        }
                    } catch (\Throwable $e) {
                        $ch->error($e);

                        return;
                    }

                    $remaining--;
                    if ($remaining <= 0) {
                        $ch->complete();
                    }
                })();
            }
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function distinct(): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev): void {
            async(static function () use ($prev, $ch, $ctx): void {
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
                } catch (\Throwable $e) {
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    /** @param callable(mixed): mixed $keyFn */
    public function distinctBy(callable $keyFn): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $keyFn): void {
            async(static function () use ($prev, $ch, $ctx, $keyFn): void {
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
                } catch (\Throwable $e) {
                    $ch->error($e);
                }
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function sample(float $seconds): self
    {
        $prev = $this;

        $emitter = new self(static function (Channel $ch, StreamContext $ctx) use ($prev, $seconds): void {
            $latest = null;
            $hasLatest = false;

            $timer = Loop::addPeriodicTimer($seconds, static function () use ($ch, &$latest, &$hasLatest): void {
                if ($hasLatest) {
                    $ch->emit($latest);
                    $hasLatest = false;
                }
            });

            $ctx->onDispose(static function () use ($timer): void {
                Loop::cancelTimer($timer);
            });

            async(static function () use ($prev, $ch, $ctx, &$latest, &$hasLatest, $timer): void {
                try {
                    foreach ($prev($ctx) as $value) {
                        $ctx->throwIfCancelled();
                        $latest = $value;
                        $hasLatest = true;
                    }
                } catch (\Throwable $e) {
                    Loop::cancelTimer($timer);
                    $ch->error($e);

                    return;
                }

                Loop::cancelTimer($timer);
                $ch->complete();
            })();
        });

        $this->copyStreamState($emitter);

        return $emitter;
    }

    public function toArray(): \Convoy\Stream\Terminal\Collect
    {
        return new \Convoy\Stream\Terminal\Collect($this);
    }

    public function reduce(callable $fn, mixed $initial = null): \Convoy\Stream\Terminal\Reduce
    {
        return new \Convoy\Stream\Terminal\Reduce($this, $fn, $initial);
    }

    public function first(): \Convoy\Stream\Terminal\First
    {
        return new \Convoy\Stream\Terminal\First($this);
    }

    public function consume(): \Convoy\Stream\Terminal\Drain
    {
        return new \Convoy\Stream\Terminal\Drain($this);
    }
}
