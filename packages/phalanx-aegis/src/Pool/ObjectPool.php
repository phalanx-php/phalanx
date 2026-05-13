<?php

declare(strict_types=1);

namespace Phalanx\Pool;

use Closure;
use ReflectionClass;
use SplStack;

/**
 * @template T of object
 */
final class ObjectPool
{
    /** @var SplStack<T> */
    private SplStack $free;

    /** @var ReflectionClass<T> */
    private ReflectionClass $reflector;

    private int $hits = 0;

    private int $misses = 0;

    private int $overflows = 0;

    /** @param class-string<T> $class */
    public function __construct(
        private(set) string $class,
        private(set) int $capacity,
    ) {
        $this->free = new SplStack();
        $this->reflector = new ReflectionClass($class);
    }

    /**
     * @param Closure(T): void $initializer
     * @return T
     */
    public function acquire(Closure $initializer): object
    {
        $bound = Closure::bind($initializer, null, $this->class);

        if ($this->free->isEmpty()) {
            $this->misses++;

            $instance = $this->reflector->newInstanceWithoutConstructor();
            $this->reflector->resetAsLazyGhost($instance, $bound);
            $this->reflector->initializeLazyObject($instance);

            return $instance;
        }

        $this->hits++;

        $instance = $this->free->pop();
        $this->reflector->resetAsLazyGhost($instance, $bound);
        $this->reflector->initializeLazyObject($instance);

        return $instance;
    }

    /** @param T $instance */
    public function release(object $instance): void
    {
        if ($this->free->count() >= $this->capacity) {
            $this->overflows++;
            return;
        }

        $this->free->push($instance);
    }

    /** @return array{hits: int, misses: int, overflows: int, free: int, capacity: int} */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'overflows' => $this->overflows,
            'free' => $this->free->count(),
            'capacity' => $this->capacity,
        ];
    }
}
