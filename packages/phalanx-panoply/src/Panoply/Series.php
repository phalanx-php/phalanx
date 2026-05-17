<?php

declare(strict_types=1);

namespace Phalanx\Panoply;

/**
 * Lazy typed iterator base. Every "stream of things" panoply exposes —
 * Cues, Conversation\Records, HomeDir\Projects, Artifact\Collections —
 * is a Series<T> specialization. Raw PHP iterators (\Generator,
 * \CallbackFilterIterator, \LimitIterator) are implementation detail
 * and never surface in the public API.
 *
 * Combinators that preserve `T` (where/take/skip/until/since/tee/merge/
 * interleave/interleaveBy) return `static`, so chained calls on a
 * subclass (e.g. {@see Stream::where()}) preserve the subclass type.
 * Type-changing combinators (`map`, `pluck`, `chunk`, `flatten`, `zip`)
 * return a base {@see Series}.
 *
 * All combinators are lazy — they wrap the upstream source's Generator
 * factory and produce a new one. Consumption begins only when
 * `getIterator()` is called or a terminal op runs (each/reduce/first/
 * last/count/toArray).
 *
 * @template T
 * @implements \IteratorAggregate<T>
 * @phpstan-consistent-constructor
 */
class Series implements \IteratorAggregate
{
    /**
     * @param \Closure(): \Generator<T> $source
     */
    final public function __construct(private readonly \Closure $source)
    {
    }

    /**
     * Construct from any iterable. Eager arrays and lazy iterators both
     * accepted — consumption remains lazy in either case. Subclass-aware:
     * calling `Stream::from(...)` returns a `Stream`, not a base Series.
     *
     * @param iterable<mixed> $items
     * @return static
     */
    public static function from(iterable $items): static
    {
        return new static(static function () use ($items): \Generator {
            yield from $items;
        });
    }

    /** @return \Generator<T> */
    final public function getIterator(): \Generator
    {
        yield from ($this->source)();
    }

    /**
     * @param callable(T): bool $predicate
     * @return static
     */
    public function where(callable $predicate): static
    {
        $source = $this->source;

        return new static(static function () use ($source, $predicate): \Generator {
            foreach ($source() as $item) {
                if ($predicate($item)) {
                    yield $item;
                }
            }
        });
    }

    /**
     * @template U
     * @param callable(T): U $fn
     * @return self<U>
     */
    public function map(callable $fn): self
    {
        $source = $this->source;

        return new self(static function () use ($source, $fn): \Generator {
            foreach ($source() as $item) {
                yield $fn($item);
            }
        });
    }

    /** @return static */
    public function take(int $n): static
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('take(n): n must be >= 0');
        }

        $source = $this->source;

        return new static(static function () use ($source, $n): \Generator {
            $remaining = $n;
            if ($remaining === 0) {
                return;
            }
            foreach ($source() as $item) {
                yield $item;
                $remaining--;
                if ($remaining === 0) {
                    return;
                }
            }
        });
    }

    /** @return static */
    public function skip(int $n): static
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('skip(n): n must be >= 0');
        }

        $source = $this->source;

        return new static(static function () use ($source, $n): \Generator {
            $remaining = $n;
            foreach ($source() as $item) {
                if ($remaining > 0) {
                    $remaining--;
                    continue;
                }
                yield $item;
            }
        });
    }

    /**
     * Yield items until the predicate matches (exclusive).
     *
     * @param callable(T): bool $predicate
     * @return static
     */
    public function until(callable $predicate): static
    {
        $source = $this->source;

        return new static(static function () use ($source, $predicate): \Generator {
            foreach ($source() as $item) {
                if ($predicate($item)) {
                    return;
                }
                yield $item;
            }
        });
    }

    /**
     * Skip items until the predicate matches; yield from that item onwards (inclusive).
     *
     * @param callable(T): bool $predicate
     * @return static
     */
    public function since(callable $predicate): static
    {
        $source = $this->source;

        return new static(static function () use ($source, $predicate): \Generator {
            $matched = false;
            foreach ($source() as $item) {
                if (!$matched && !$predicate($item)) {
                    continue;
                }
                $matched = true;
                yield $item;
            }
        });
    }

    /**
     * Side-effect on each item without consuming; pass-through.
     *
     * @param callable(T): void $observer
     * @return static
     */
    public function tee(callable $observer): static
    {
        $source = $this->source;

        return new static(static function () use ($source, $observer): \Generator {
            foreach ($source() as $item) {
                $observer($item);
                yield $item;
            }
        });
    }

    /**
     * Project a field/property. Property access if T is object; array key access if T is array.
     *
     * @return self<mixed>
     */
    public function pluck(string $field): self
    {
        $source = $this->source;

        return new self(static function () use ($source, $field): \Generator {
            foreach ($source() as $item) {
                if (is_array($item)) {
                    yield $item[$field] ?? null;
                    continue;
                }
                if (is_object($item)) {
                    yield $item->{$field} ?? null;
                    continue;
                }
                yield null;
            }
        });
    }

    /**
     * Yield non-overlapping batches of size `size`. Final batch may be shorter.
     *
     * @return self<list<T>>
     */
    public function chunk(int $size): self
    {
        if ($size < 1) {
            throw new \InvalidArgumentException('chunk(size): size must be >= 1');
        }

        $source = $this->source;

        return new self(static function () use ($source, $size): \Generator {
            $buf = [];
            foreach ($source() as $item) {
                $buf[] = $item;
                if (count($buf) === $size) {
                    yield $buf;
                    $buf = [];
                }
            }
            if ($buf !== []) {
                yield $buf;
            }
        });
    }

    /**
     * Yield items from inner iterables one level deep.
     *
     * @return self<mixed>
     */
    public function flatten(): self
    {
        $source = $this->source;

        return new self(static function () use ($source): \Generator {
            foreach ($source() as $outer) {
                if (is_iterable($outer)) {
                    yield from $outer;
                    continue;
                }
                yield $outer;
            }
        });
    }

    /**
     * Concatenate this series and others in declared order; preserves subclass type.
     *
     * @param Series<T> ...$others
     * @return static
     */
    public function merge(self ...$others): static
    {
        $source = $this->source;

        return new static(static function () use ($source, $others): \Generator {
            yield from $source();
            foreach ($others as $other) {
                yield from $other;
            }
        });
    }

    /**
     * Round-robin items across this series and others. Stops when all sources are exhausted.
     *
     * @param Series<T> ...$others
     * @return static
     */
    public function interleave(self ...$others): static
    {
        $source = $this->source;

        return new static(static function () use ($source, $others): \Generator {
            $iterators = [$source()];
            foreach ($others as $other) {
                $iterators[] = $other->getIterator();
            }

            while ($iterators !== []) {
                foreach ($iterators as $key => $iter) {
                    if (!$iter->valid()) {
                        unset($iterators[$key]);
                        continue;
                    }
                    yield $iter->current();
                    $iter->next();
                }
            }
        });
    }

    /**
     * Sort-merge by `key`. Each source MUST already be ordered by the same key
     * function; the merge yields items in ascending key order. On ties the
     * earlier-declared source wins.
     *
     * When `dedupBy` is supplied, items producing the same dedup string are
     * yielded at most once across all sources. Typical use: pass a content
     * hash extractor when merging overlapping JSONL files and a SQLite log.
     *
     * @param callable(T): (int|string|float) $key
     * @param Series<T>                       ...$others
     * @return static
     */
    public function interleaveBy(callable $key, self ...$others): static
    {
        return $this->interleaveByImpl($key, null, ...$others);
    }

    /**
     * @param callable(T): (int|string|float) $key
     * @param callable(T): string             $dedupBy
     * @param Series<T>                       ...$others
     * @return static
     */
    public function interleaveByDedup(callable $key, callable $dedupBy, self ...$others): static
    {
        return $this->interleaveByImpl($key, $dedupBy, ...$others);
    }

    /**
     * Zip multiple series into a tuple stream. Stops at shortest.
     *
     * @param Series<mixed> ...$others
     * @return self<list<mixed>>
     */
    public function zip(self ...$others): self
    {
        $source = $this->source;

        return new self(static function () use ($source, $others): \Generator {
            $iterators = [$source()];
            foreach ($others as $other) {
                $iterators[] = $other->getIterator();
            }

            while (true) {
                $tuple = [];
                foreach ($iterators as $iter) {
                    if (!$iter->valid()) {
                        return;
                    }
                    $tuple[] = $iter->current();
                }
                yield $tuple;
                foreach ($iterators as $iter) {
                    $iter->next();
                }
            }
        });
    }

    /**
     * Fully consume; reduce to a single value.
     *
     * @template R
     * @param callable(R, T): R $fn
     * @param R $initial
     * @return R
     */
    public function reduce(callable $fn, mixed $initial): mixed
    {
        $acc = $initial;
        foreach ($this as $item) {
            $acc = $fn($acc, $item);
        }
        /** @var R $acc */
        return $acc;
    }

    /**
     * Fully consume; invoke a callback for each item.
     *
     * @param callable(T): void $fn
     */
    public function each(callable $fn): void
    {
        foreach ($this as $item) {
            $fn($item);
        }
    }

    /**
     * Consume just enough to produce the first item; return null if empty.
     *
     * @return T|null
     */
    public function first(): mixed
    {
        foreach ($this as $item) {
            return $item;
        }

        return null;
    }

    /**
     * Fully consume; return the last item, or null if empty.
     *
     * @return T|null
     */
    public function last(): mixed
    {
        $last = null;
        foreach ($this as $item) {
            $last = $item;
        }

        return $last;
    }

    /**
     * Fully consume; count items.
     */
    public function count(): int
    {
        $n = 0;
        foreach ($this as $_ignored) {
            $n++;
        }

        return $n;
    }

    /**
     * Fully consume; materialize as a list. Use sparingly — defeats the purpose
     * of laziness for large series. Useful for tests and small bounded results.
     *
     * @return list<T>
     */
    public function toArray(): array
    {
        $out = [];
        foreach ($this as $item) {
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param callable(T): (int|string|float) $key
     * @param (callable(T): string)|null      $dedupBy
     * @param Series<T>                       ...$others
     * @return static
     */
    private function interleaveByImpl(callable $key, ?callable $dedupBy, self ...$others): static
    {
        $source = $this->source;

        return new static(static function () use ($source, $key, $dedupBy, $others): \Generator {
            $iterators = [$source()];
            foreach ($others as $other) {
                $iterators[] = $other->getIterator();
            }

            $seen = [];

            while ($iterators !== []) {
                $bestIdx = null;
                $bestKey = null;

                foreach ($iterators as $idx => $iter) {
                    if (!$iter->valid()) {
                        unset($iterators[$idx]);
                        continue;
                    }
                    $candidateKey = $key($iter->current());
                    if ($bestKey === null || $candidateKey < $bestKey) {
                        $bestKey = $candidateKey;
                        $bestIdx = $idx;
                    }
                }

                if ($bestIdx === null) {
                    break;
                }

                $iter = $iterators[$bestIdx];
                $current = $iter->current();
                $iter->next();

                if ($dedupBy !== null) {
                    $digest = $dedupBy($current);
                    if (isset($seen[$digest])) {
                        continue;
                    }
                    $seen[$digest] = true;
                }

                yield $current;
            }
        });
    }
}
