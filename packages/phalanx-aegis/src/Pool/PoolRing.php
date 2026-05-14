<?php

declare(strict_types=1);

namespace Phalanx\Pool;

use Closure;
use ReflectionClass;

/**
 * @template T of BorrowedValue
 */
final class PoolRing
{
    /** @var list<T> */
    private array $slots;

    /** @var array<int, bool> */
    private array $borrowed;

    private int $cursor = 0;

    /** @var ReflectionClass<T> */
    private ReflectionClass $reflector;

    /** @param class-string<T> $class */
    public function __construct(
        private(set) string $class,
        private(set) int $size,
    ) {
        if (!is_a($class, BorrowedValue::class, true)) {
            throw new \LogicException('PoolRing classes must implement BorrowedValue.');
        }

        $this->reflector = new ReflectionClass($class);
        $this->slots = [];
        $this->borrowed = [];

        for ($i = 0; $i < $size; $i++) {
            $ghost = $this->reflector->newLazyGhost(static function (): void {
            });
            $this->slots[] = $ghost;
            $this->borrowed[] = false;
        }
    }

    /**
     * @template R
     * @param Closure(T): void $initializer
     * @param Closure(T): R $borrow
     * @return R
     */
    public function withBorrowed(Closure $initializer, Closure $borrow): mixed
    {
        $idx = $this->claimSlot();

        try {
            $slot = $this->slots[$idx];
            $bound = Closure::bind($initializer, null, $this->class);

            if ($this->reflector->isUninitializedLazyObject($slot)) {
                $this->reflector->markLazyObjectAsInitialized($slot);
            }

            $this->reflector->resetAsLazyGhost($slot, $bound);
            $this->reflector->initializeLazyObject($slot);

            $result = $borrow($slot);
            if (self::containsBorrowedValue($result)) {
                throw new \LogicException('Borrow callbacks must return owned values, not borrowed pool slots.');
            }

            return $result;
        } finally {
            $this->borrowed[$idx] = false;
        }
    }

    public function reset(): void
    {
        if (in_array(true, $this->borrowed, true)) {
            throw new \LogicException('Cannot reset PoolRing while values are borrowed.');
        }

        $this->cursor = 0;

        for ($i = 0; $i < $this->size; $i++) {
            if ($this->reflector->isUninitializedLazyObject($this->slots[$i])) {
                $this->reflector->markLazyObjectAsInitialized($this->slots[$i]);
            }

            $this->reflector->resetAsLazyGhost(
                $this->slots[$i],
                static function (): void {
                },
            );
        }
    }

    private static function containsBorrowedValue(mixed $value, int $depth = 0): bool
    {
        if ($value instanceof BorrowedValue) {
            return true;
        }

        if (!is_array($value) || $depth > 16) {
            return false;
        }

        foreach ($value as $item) {
            if (self::containsBorrowedValue($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function claimSlot(): int
    {
        for ($i = 0; $i < $this->size; $i++) {
            $idx = $this->cursor;
            $this->cursor = ($this->cursor + 1) % $this->size;

            if (!$this->borrowed[$idx]) {
                $this->borrowed[$idx] = true;
                return $idx;
            }
        }

        throw new \LogicException('PoolRing exhausted: all slots are currently borrowed.');
    }
}
