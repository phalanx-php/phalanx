<?php

declare(strict_types=1);

namespace Phalanx\Pool;

use Closure;
use ReflectionClass;

/**
 * @template T of object
 */
final class PoolRing
{
    /** @var list<T> */
    private array $slots;

    private int $cursor = 0;

    /** @var ReflectionClass<T> */
    private ReflectionClass $reflector;

    /** @param class-string<T> $class */
    public function __construct(
        private(set) string $class,
        private(set) int $size,
    ) {
        $this->reflector = new ReflectionClass($class);
        $this->slots = [];

        for ($i = 0; $i < $size; $i++) {
            /** @var T $ghost */
            $ghost = $this->reflector->newLazyGhost(static function (): void {
            });
            $this->slots[] = $ghost;
        }
    }

    /**
     * @param Closure(T): void $initializer
     * @return T
     */
    public function next(Closure $initializer): object
    {
        $slot = $this->slots[$this->cursor];

        if ($this->reflector->isUninitializedLazyObject($slot)) {
            $this->reflector->initializeLazyObject($slot);
        }

        $this->reflector->resetAsLazyGhost($slot, $initializer);
        $this->reflector->initializeLazyObject($slot);
        $this->cursor = ($this->cursor + 1) % $this->size;

        return $slot;
    }

    public function reset(): void
    {
        $this->cursor = 0;

        for ($i = 0; $i < $this->size; $i++) {
            if ($this->reflector->isUninitializedLazyObject($this->slots[$i])) {
                $this->reflector->initializeLazyObject($this->slots[$i]);
            }

            $this->reflector->resetAsLazyGhost(
                $this->slots[$i],
                static function (): void {
                },
            );
        }
    }
}
