<?php

declare(strict_types=1);

namespace Phalanx\Styx;

use Closure;
use Generator;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Stream\Streamable;
use Phalanx\Scope\Stream\StreamSource;
use Phalanx\Styx\Terminal\Collect;
use Phalanx\Styx\Terminal\Drain;
use Phalanx\Styx\Terminal\First;
use Phalanx\Styx\Terminal\Reduce;
use Phalanx\Supervisor\TaskRun;
use Throwable;

/**
 * @implements StreamSource<mixed>
 */
final class Emitter implements StreamSource
{
    use Streamable;

    /** @var Closure(Channel, ExecutionScope): (?Closure) */
    private readonly Closure $setup;

    /** @param Closure(Channel, ExecutionScope): (?Closure) $setup */
    private function __construct(Closure $setup)
    {
        $this->setup = $setup;
        $this->initStreamState();
    }

    /** @param Closure(Channel, ExecutionScope): void $producer */
    public static function produce(Closure $producer): self
    {
        return new self(static function (Channel $ch, ExecutionScope $scope) use ($producer): Closure {
            $run = $scope->go(static function (ExecutionScope $producerScope) use ($producer, $ch): void {
                try {
                    $producer($ch, $producerScope);
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $ch->error($e);
                    return;
                }
                $ch->complete();
            }, 'styx.produce');

            return static function () use ($run): void {
                $run->cancellation->cancel();
            };
        });
    }

    public static function interval(float $seconds): self
    {
        return new self(static function (Channel $ch, ExecutionScope $scope) use ($seconds): Closure {
            $tick = 0;
            $subscription = $scope->periodic($seconds, static function () use ($ch, &$tick): void {
                $ch->emit(++$tick);
            });

            if ($subscription->cancelled) {
                $ch->complete();
            }

            return static function () use ($subscription, $ch): void {
                $subscription->cancel();
                $ch->complete();
            };
        });
    }

    public function __invoke(ExecutionScope $scope): Generator
    {
        $channel = new Channel();
        $cleanup = null;

        try {
            $cleanup = ($this->setup)($channel, $scope);
            $this->fireOnStart($scope);

            foreach ($channel->consume() as $value) {
                $scope->throwIfCancelled();
                $this->fireOnEach($value, $scope);
                yield $value;
            }
            $this->fireOnComplete($scope);
        } catch (Throwable $e) {
            $this->fireOnError($e, $scope);
            throw $e;
        } finally {
            if ($cleanup instanceof Closure) {
                $cleanup();
            }
            $channel->complete();
            $this->fireOnDispose($scope);
        }
    }

    /** @param Closure(mixed, int): mixed $fn */
    public function map(Closure $fn): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $fn): Closure {
            $run = $scope->go(static function (ExecutionScope $childScope) use ($prev, $ch, $fn): void {
                try {
                    foreach ($prev($childScope) as $key => $value) {
                        $childScope->throwIfCancelled();
                        $ch->emit($fn($value, $key));
                    }
                    $ch->complete();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            }, 'styx.map');

            return static function () use ($run): void {
                $run->cancellation->cancel();
            };
        });
    }

    /** @param Closure(mixed, int): bool $predicate */
    public function filter(Closure $predicate): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $predicate): Closure {
            $run = $scope->go(static function (ExecutionScope $childScope) use ($prev, $ch, $predicate): void {
                try {
                    foreach ($prev($childScope) as $key => $value) {
                        $childScope->throwIfCancelled();
                        if ($predicate($value, $key)) {
                            $ch->emit($value);
                        }
                    }
                    $ch->complete();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            }, 'styx.filter');

            return static function () use ($run): void {
                $run->cancellation->cancel();
            };
        });
    }

    public function take(int $n): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $n): Closure {
            $run = $scope->go(static function (ExecutionScope $childScope) use ($prev, $ch, $n): void {
                try {
                    $count = 0;
                    foreach ($prev($childScope) as $value) {
                        $childScope->throwIfCancelled();
                        $ch->emit($value);
                        if (++$count >= $n) {
                            break;
                        }
                    }
                    $ch->complete();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            }, 'styx.take');

            return static function () use ($run): void {
                $run->cancellation->cancel();
            };
        });
    }

    public function throttle(float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $seconds): Closure {
            $run = $scope->go(static function (ExecutionScope $childScope) use ($prev, $ch, $seconds): void {
                $lastEmitNs = 0.0;
                $intervalNs = $seconds * 1e9;

                try {
                    foreach ($prev($childScope) as $value) {
                        $childScope->throwIfCancelled();
                        $now = (float) hrtime(true);
                        if (($now - $lastEmitNs) >= $intervalNs) {
                            $ch->emit($value);
                            $lastEmitNs = $now;
                        }
                    }
                    $ch->complete();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            }, 'styx.throttle');

            return static function () use ($run): void {
                $run->cancellation->cancel();
            };
        });
    }

    public function debounce(float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $seconds): Closure {
            $delaySeconds = max(0.001, $seconds);
            /** @var TaskRun|null $timerRun */
            $timerRun = null;
            $producerRun = $scope->go(static function (ExecutionScope $childScope) use (
                $prev,
                $ch,
                $delaySeconds,
                &$timerRun,
            ): void {
                $latest = null;
                $hasLatest = false;
                $version = 0;

                try {
                    foreach ($prev($childScope) as $value) {
                        $childScope->throwIfCancelled();
                        $timerRun?->cancellation->cancel();
                        $latest = $value;
                        $hasLatest = true;
                        $version++;

                        $currentVersion = $version;
                        $timerRun = $childScope->go(static function (ExecutionScope $timerScope) use (
                            $ch,
                            $delaySeconds,
                            $currentVersion,
                            &$latest,
                            &$hasLatest,
                            &$version,
                        ): void {
                            $timerScope->delay($delaySeconds);
                            if ($hasLatest && $version === $currentVersion) {
                                $ch->emit($latest);
                                $hasLatest = false;
                            }
                        }, 'styx.debounce.timer');
                    }

                    $timerRun?->cancellation->cancel();
                    if ($hasLatest) {
                        $ch->emit($latest);
                    }
                    $ch->complete();
                } catch (Cancelled $e) {
                    $timerRun?->cancellation->cancel();
                    throw $e;
                } catch (Throwable $e) {
                    $timerRun?->cancellation->cancel();
                    $ch->error($e);
                }
            }, 'styx.debounce');

            return static function () use ($producerRun, &$timerRun): void {
                $timerRun?->cancellation->cancel();
                $producerRun->cancellation->cancel();
            };
        });
    }

    public function bufferWindow(int $count, float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $count, $seconds): Closure {
            $delaySeconds = max(0.001, $seconds);
            /** @var TaskRun|null $timerRun */
            $timerRun = null;
            $producerRun = $scope->go(static function (ExecutionScope $childScope) use (
                $prev,
                $ch,
                $count,
                $delaySeconds,
                &$timerRun,
            ): void {
                /** @var list<mixed> $buffer */
                $buffer = [];
                $version = 0;

                $flush = static function () use ($ch, &$buffer, &$timerRun, &$version): void {
                    if ($buffer !== []) {
                        $batch = $buffer;
                        $buffer = [];
                        $ch->emit($batch);
                    }
                    $timerRun?->cancellation->cancel();
                    $timerRun = null;
                    $version++;
                };

                try {
                    foreach ($prev($childScope) as $value) {
                        $childScope->throwIfCancelled();
                        $buffer[] = $value;

                        if ($timerRun === null) {
                            $currentVersion = $version;
                            $timerRun = $childScope->go(static function (ExecutionScope $timerScope) use (
                                $delaySeconds,
                                $flush,
                                $currentVersion,
                                &$version,
                            ): void {
                                $timerScope->delay($delaySeconds);
                                if ($version === $currentVersion) {
                                    $flush();
                                }
                            }, 'styx.buffer_window.timer');
                        }

                        if (count($buffer) >= $count) {
                            $flush();
                        }
                    }

                    $flush();
                    $ch->complete();
                } catch (Cancelled $e) {
                    $timerRun?->cancellation->cancel();
                    throw $e;
                } catch (Throwable $e) {
                    $timerRun?->cancellation->cancel();
                    $ch->error($e);
                }
            }, 'styx.buffer_window');

            return static function () use ($producerRun, &$timerRun): void {
                $timerRun?->cancellation->cancel();
                $producerRun->cancellation->cancel();
            };
        });
    }

    public function merge(self ...$others): self
    {
        $sources = [$this, ...$others];

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($sources): Closure {
            $remaining = count($sources);
            $failed = false;
            $completed = false;
            /** @var list<TaskRun> $runs */
            $runs = [];

            foreach ($sources as $source) {
                $runs[] = $scope->go(static function (ExecutionScope $childScope) use (
                    $source,
                    $ch,
                    &$remaining,
                    &$failed,
                    &$completed,
                ): void {
                    try {
                        foreach ($source($childScope) as $value) {
                            $childScope->throwIfCancelled();
                            if ($failed) {
                                return;
                            }
                            $ch->emit($value);
                        }
                    } catch (Cancelled $e) {
                        throw $e;
                    } catch (Throwable $e) {
                        if (!$failed) {
                            $failed = true;
                            $ch->error($e);
                        }
                        return;
                    }

                    $remaining--;
                    if ($remaining <= 0 && !$failed && !$completed) {
                        $completed = true;
                        $ch->complete();
                    }
                }, 'styx.merge');
            }

            return static function () use (&$runs): void {
                foreach ($runs as $run) {
                    $run->cancellation->cancel();
                }
            };
        });
    }

    public function distinct(): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev): Closure {
            $run = $scope->go(static function (ExecutionScope $childScope) use ($prev, $ch): void {
                $hasLast = false;
                $lastValue = null;

                try {
                    foreach ($prev($childScope) as $value) {
                        $childScope->throwIfCancelled();
                        if (!$hasLast || $value !== $lastValue) {
                            $ch->emit($value);
                            $lastValue = $value;
                            $hasLast = true;
                        }
                    }
                    $ch->complete();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            }, 'styx.distinct');

            return static function () use ($run): void {
                $run->cancellation->cancel();
            };
        });
    }

    /** @param Closure(mixed): mixed $keyFn */
    public function distinctBy(Closure $keyFn): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $keyFn): Closure {
            $run = $scope->go(static function (ExecutionScope $childScope) use ($prev, $ch, $keyFn): void {
                $hasLastKey = false;
                $lastKey = null;

                try {
                    foreach ($prev($childScope) as $value) {
                        $childScope->throwIfCancelled();
                        $key = $keyFn($value);
                        if (!$hasLastKey || $key !== $lastKey) {
                            $ch->emit($value);
                            $lastKey = $key;
                            $hasLastKey = true;
                        }
                    }
                    $ch->complete();
                } catch (Cancelled $e) {
                    throw $e;
                } catch (Throwable $e) {
                    $ch->error($e);
                }
            }, 'styx.distinct_by');

            return static function () use ($run): void {
                $run->cancellation->cancel();
            };
        });
    }

    public function sample(float $seconds): self
    {
        $prev = $this;

        return new self(static function (Channel $ch, ExecutionScope $scope) use ($prev, $seconds): Closure {
            $state = new class () {
                public mixed $latest = null;
                public bool $hasLatest = false;
            };

            $subscription = $scope->periodic($seconds, static function () use ($ch, $state): void {
                if ($state->hasLatest) {
                    $ch->emit($state->latest);
                    $state->hasLatest = false;
                }
            });

            $run = $scope->go(static function (ExecutionScope $childScope) use (
                $prev,
                $ch,
                $state,
                $subscription,
            ): void {
                try {
                    foreach ($prev($childScope) as $value) {
                        $childScope->throwIfCancelled();
                        $state->latest = $value;
                        $state->hasLatest = true;
                    }
                    $subscription->cancel();
                    $ch->complete();
                } catch (Cancelled $e) {
                    $subscription->cancel();
                    throw $e;
                } catch (Throwable $e) {
                    $subscription->cancel();
                    $ch->error($e);
                }
            }, 'styx.sample');

            return static function () use ($run, $subscription): void {
                $subscription->cancel();
                $run->cancellation->cancel();
            };
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
}
