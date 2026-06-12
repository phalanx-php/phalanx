<?php

declare(strict_types=1);

namespace Phalanx\Err;

use Throwable;

final class FaultLink
{
    /** @param list<string> $lineage */
    public function __construct(
        private(set) string $class,
        private(set) array $lineage,
        private(set) string $message,
    ) {
    }

    public static function fromThrowable(Throwable $throwable): self
    {
        $class = $throwable::class;
        $lineage = [$class, ...array_values(class_parents($throwable)), ...array_values(class_implements($throwable))];

        return new self($class, $lineage, $throwable->getMessage());
    }

    public function matches(string ...$classes): bool
    {
        return array_intersect($classes, $this->lineage) !== [];
    }
}
