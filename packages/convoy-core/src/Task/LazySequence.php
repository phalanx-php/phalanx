<?php

declare(strict_types=1);

namespace Convoy\Task;

use Convoy\ExecutionScope;
use Convoy\Stream\Contract\Streamable;
use Convoy\Stream\Contract\StreamContext;
use Convoy\Stream\Contract\StreamSource;
use Generator;

use function React\Async\async;
use function React\Async\await;
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
                await(race($pending));

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
        $terminal = new \Convoy\Stream\Terminal\Collect($this);
        return new readonly class ($terminal) implements Executable {
            public function __construct(private \Convoy\Stream\Terminal\Collect $terminal)
            {
            }
            public function __invoke(ExecutionScope $scope): array
            {
                return ($this->terminal)($scope);
            }
        };
    }

    public function reduce(callable $fn, mixed $initial = null): Executable
    {
        $terminal = new \Convoy\Stream\Terminal\Reduce($this, $fn(...), $initial);
        return new readonly class ($terminal) implements Executable {
            public function __construct(private \Convoy\Stream\Terminal\Reduce $terminal)
            {
            }
            public function __invoke(ExecutionScope $scope): mixed
            {
                return ($this->terminal)($scope);
            }
        };
    }

    public function first(): Executable
    {
        $terminal = new \Convoy\Stream\Terminal\First($this);
        return new readonly class ($terminal) implements Executable {
            public function __construct(private \Convoy\Stream\Terminal\First $terminal)
            {
            }
            public function __invoke(ExecutionScope $scope): mixed
            {
                return ($this->terminal)($scope);
            }
        };
    }

    public function consume(): Executable
    {
        $terminal = new \Convoy\Stream\Terminal\Drain($this);
        return new readonly class ($terminal) implements Executable {
            public function __construct(private \Convoy\Stream\Terminal\Drain $terminal)
            {
            }
            public function __invoke(ExecutionScope $scope): null
            {
                return ($this->terminal)($scope);
            }
        };
    }
}
