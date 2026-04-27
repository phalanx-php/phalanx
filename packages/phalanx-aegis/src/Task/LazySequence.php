<?php

declare(strict_types=1);

namespace Phalanx\Task;

use Generator;
use Phalanx\ExecutionScope;
use Phalanx\Stream\Contract\Streamable;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Contract\StreamSource;

use function React\Async\async;
use function React\Promise\race;

final class LazySequence implements StreamSource, Executable
{
    use Streamable;

    private function __construct(
        private readonly \Closure $factory,
    ) {
        $this->initStreamState();
    }

    public static function from(callable $factory): self
    {
        return new self($factory(...));
    }

    /** @param iterable<mixed> $items */
    public static function of(iterable $items): self
    {
        return new self(static function (ExecutionScope $s) use ($items): Generator {
            yield from $items;
        });
    }

    /**
     * @param array<int|string, mixed> $batch
     * @return Generator<mixed>
     */
    private static function dispatchParallelBatch(
        ExecutionScope $scope,
        array $batch,
        callable $fn,
        int $concurrency,
        bool $unordered,
    ): Generator {
        if ($unordered) {
            $keys = array_keys($batch);
            $results = [];
            $pending = [];

            foreach ($batch as $key => $value) {
                $task = $fn($value);
                $currentKey = $key;

                $pending[$currentKey] = async(static function () use ($scope, $task, $currentKey, &$results): mixed {
                    $results[$currentKey] = $scope->inWorker($task);

                    return null;
                })();
            }

            while ($pending !== []) {
                $scope->await(race($pending));

                foreach ($results as $key => $result) {
                    unset($pending[$key]);
                    yield $key => $result;
                }

                $results = [];
            }
        } else {
            $tasks = [];
            foreach ($batch as $key => $value) {
                $tasks[$key] = $fn($value);
            }

            foreach ($tasks as $key => $task) {
                yield $key => $scope->inWorker($task);
            }
        }
    }

    public function map(callable $fn): self
    {
        $source = $this->factory;

        $seq = new self(static function (ExecutionScope $s) use ($source, $fn): Generator {
            foreach ($source($s) as $key => $value) {
                $s->throwIfCancelled();
                yield $key => $fn($value, $key, $s);
            }
        });

        $this->copyStreamState($seq);

        return $seq;
    }

    public function filter(callable $predicate): self
    {
        $source = $this->factory;

        $seq = new self(static function (ExecutionScope $s) use ($source, $predicate): Generator {
            foreach ($source($s) as $key => $value) {
                $s->throwIfCancelled();
                if ($predicate($value, $key, $s)) {
                    yield $key => $value;
                }
            }
        });

        $this->copyStreamState($seq);

        return $seq;
    }

    public function take(int $n): self
    {
        $source = $this->factory;

        $seq = new self(static function (ExecutionScope $s) use ($source, $n): Generator {
            $count = 0;
            foreach ($source($s) as $key => $value) {
                if ($count >= $n) {
                    break;
                }
                $s->throwIfCancelled();
                yield $key => $value;
                $count++;
            }
        });

        $this->copyStreamState($seq);

        return $seq;
    }

    public function chunk(int $size): self
    {
        $source = $this->factory;

        $seq = new self(static function (ExecutionScope $s) use ($source, $size): Generator {
            $chunk = [];
            foreach ($source($s) as $value) {
                $s->throwIfCancelled();
                $chunk[] = $value;
                if (count($chunk) >= $size) {
                    yield $chunk;
                    $chunk = [];
                }
            }
            if (!empty($chunk)) {
                yield $chunk;
            }
        });

        $this->copyStreamState($seq);

        return $seq;
    }

    public function mapConcurrent(callable $fn, int $concurrency = 10): self
    {
        $source = $this->factory;

        $seq = new self(static function (ExecutionScope $s) use ($source, $fn, $concurrency): Generator {
            $batch = [];
            foreach ($source($s) as $key => $value) {
                $batch[$key] = $value;

                if (count($batch) >= $concurrency) {
                    $results = $s->map(
                        items: $batch,
                        fn: static fn($v) => new Transform($v, static fn($val, $scope) => $fn($val, $scope)),
                        limit: $concurrency,
                    );
                    yield from $results;
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                $results = $s->map(
                    items: $batch,
                    fn: static fn($v) => new Transform($v, static fn($val, $scope) => $fn($val, $scope)),
                    limit: $concurrency,
                );
                yield from $results;
            }
        });

        $this->copyStreamState($seq);

        return $seq;
    }

    public function mapParallel(callable $fn, int $concurrency = 4): self
    {
        $source = $this->factory;
        $unordered = $this->unorderedFlag;

        $seq = new self(static function (ExecutionScope $s) use ($source, $fn, $concurrency, $unordered): Generator {
            $batch = [];
            foreach ($source($s) as $key => $value) {
                $batch[$key] = $value;

                if (count($batch) >= $concurrency) {
                    yield from self::dispatchParallelBatch($s, $batch, $fn, $concurrency, $unordered);
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                yield from self::dispatchParallelBatch($s, $batch, $fn, $concurrency, $unordered);
            }
        });

        $this->copyStreamState($seq);
        $seq->unorderedFlag = false;

        return $seq;
    }

    public function toArray(): Executable
    {
        return new TerminalTask(new \Phalanx\Stream\Terminal\Collect($this));
    }

    public function reduce(callable $fn, mixed $initial = null): Executable
    {
        return new TerminalTask(new \Phalanx\Stream\Terminal\Reduce($this, $fn(...), $initial));
    }

    public function first(): Executable
    {
        return new TerminalTask(new \Phalanx\Stream\Terminal\First($this));
    }

    public function consume(): Executable
    {
        return new TerminalTask(new \Phalanx\Stream\Terminal\Drain($this));
    }

    public function __invoke(StreamContext|ExecutionScope $scope): Generator
    {
        $this->fireOnStart($scope);

        try {
            foreach (($this->factory)($scope) as $key => $value) {
                $scope->throwIfCancelled();
                $this->fireOnEach($value, $scope);
                yield $key => $value;
            }
            $this->fireOnComplete($scope);
        } catch (\Throwable $e) {
            $this->fireOnError($e, $scope);
            throw $e;
        } finally {
            $this->fireOnDispose($scope);
        }
    }
}

/**
 * Bridges a StreamContext-accepting terminal into the Executable interface.
 *
 * Terminal operations (Collect, Reduce, First, Drain) consume a StreamContext.
 * ExecutionScope satisfies StreamContext, so this wrapper accepts the wider
 * ExecutionScope required by Executable and delegates down to the terminal.
 *
 * @internal
 */
final readonly class TerminalTask implements Executable
{
    /** @param callable(StreamContext): mixed $terminal */
    public function __construct(private mixed $terminal)
    {
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->terminal)($scope);
    }
}
