<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use ReflectionClass;

final class MemorySliceTable
{
    private Slice $state;

    /** @param class-string<Slice> $class */
    public function __construct(
        private(set) string $class,
    ) {
        $ref = new ReflectionClass($class);
        $constructor = $ref->getConstructor();
        $args = [];

        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if (!$parameter->isDefaultValueAvailable()) {
                    throw UnsupportedSliceSchema::constructor($class);
                }

                $args[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }

        $slice = new $class(...$args);
        if (!$slice instanceof Slice) {
            throw UnsupportedSliceSchema::constructor($class);
        }

        $this->state = $slice;
    }

    public function read(): Slice
    {
        return $this->state;
    }

    public function write(Slice $slice): void
    {
        if (!$slice instanceof $this->class) {
            throw new StoreException(sprintf('Expected %s, got %s.', $this->class, $slice::class));
        }

        $this->state = $slice;
    }

    public function matches(Slice $left, Slice $right): bool
    {
        return $left === $right;
    }
}
