<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

use Closure;

final class StoreWriter
{
    public function __construct(
        private readonly StoreRegistry $registry,
    ) {
    }

    public function set(Slice $slice): Slice
    {
        return $this->update($slice::class, static fn(Slice $current): Slice => $slice);
    }

    /**
     * @template T of Slice
     * @param class-string<T> $slice
     * @param Closure(T): T $update
     * @return T
     */
    public function update(string $slice, Closure $update): Slice
    {
        return $this->registry->runtime($slice)->update(
            $slice,
            static fn(Slice $current): Slice => $update($current),
        );
    }
}
