<?php

declare(strict_types=1);

namespace Phalanx\Pool;

use Closure;
use ReflectionClass;
use ReflectionFunction;
use SplStack;

/**
 * @template T of BorrowedValue
 */
final class ObjectPool
{
    /** @var SplStack<T> */
    private SplStack $free;

    /** @var ReflectionClass<T> */
    private ReflectionClass $reflector;

    /** @var array<int, true> */
    private array $borrowed = [];

    private int $hits = 0;

    private int $misses = 0;

    private int $overflows = 0;

    private int $drops = 0;

    /** @param class-string<T> $class */
    public function __construct(
        private(set) string $class,
        private(set) int $capacity,
    ) {
        if (!is_a($class, BorrowedValue::class, true)) {
            throw new \LogicException('ObjectPool classes must implement BorrowedValue.');
        }

        $this->free = new SplStack();
        $this->reflector = new ReflectionClass($class);
    }

    /**
     * @param Closure(T): void $initializer
     * @return T
     * @internal Prefer callback-scoped borrowing for new pool call sites.
     */
    public function acquire(Closure $initializer): object
    {
        $bound = Closure::bind($initializer, null, $this->class);

        if ($this->free->isEmpty()) {
            $this->misses++;

            $instance = $this->reflector->newInstanceWithoutConstructor();
            $this->reflector->resetAsLazyGhost($instance, $bound);
            $this->reflector->initializeLazyObject($instance);
            $this->borrowed[spl_object_id($instance)] = true;

            return $instance;
        }

        $this->hits++;

        $instance = $this->free->pop();
        try {
            $this->reflector->resetAsLazyGhost($instance, $bound);
            $this->reflector->initializeLazyObject($instance);
        } catch (\Throwable $e) {
            $this->drops++;

            throw $e;
        }
        $this->borrowed[spl_object_id($instance)] = true;

        return $instance;
    }

    /**
     * @param T $instance
     * @internal Prefer callback-scoped borrowing for new pool call sites.
     */
    public function release(object $instance): void
    {
        if (!$instance instanceof $this->class) {
            throw new \LogicException("ObjectPool({$this->class}) cannot release a foreign object.");
        }

        $id = spl_object_id($instance);
        if (!isset($this->borrowed[$id])) {
            throw new \LogicException("ObjectPool({$this->class}) cannot release an object that is not borrowed.");
        }
        unset($this->borrowed[$id]);

        if ($this->free->count() >= $this->capacity) {
            $this->overflows++;
            return;
        }

        $this->free->push($instance);
    }

    /**
     * @template R
     * @param Closure(T): void $initializer
     * @param Closure(T): R $borrow
     * @return R
     */
    public function withBorrowed(Closure $initializer, Closure $borrow): mixed
    {
        $instance = $this->acquire($initializer);

        try {
            $result = $borrow($instance);
            if (self::containsBorrowedValue($result)) {
                throw new \LogicException('Borrow callbacks must return owned values, not borrowed pool slots.');
            }

            return $result;
        } finally {
            $this->release($instance);
        }
    }

    /** @return array{hits: int, misses: int, overflows: int, drops: int, borrowed: int, free: int, capacity: int} */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'overflows' => $this->overflows,
            'drops' => $this->drops,
            'borrowed' => count($this->borrowed),
            'free' => $this->free->count(),
            'capacity' => $this->capacity,
        ];
    }

    private static function containsBorrowedValue(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof BorrowedValue) {
            return true;
        }

        if ($depth > 16) {
            return false;
        }

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);
            if (self::containsBorrowedValue($reflection->getClosureThis(), $depth + 1)) {
                return true;
            }

            foreach ($reflection->getStaticVariables() as $captured) {
                if (self::containsBorrowedValue($captured, $depth + 1)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsBorrowedValue($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }
}
