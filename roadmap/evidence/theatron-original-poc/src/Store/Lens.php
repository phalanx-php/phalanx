<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

final class Lens
{
    public function __construct(
        private readonly StoreRegistry $registry,
    ) {
    }

    /**
     * @template T of Slice
     * @param class-string<T> $slice
     * @return StoreHandle<T>
     */
    public function handle(string $slice): StoreHandle
    {
        $runtime = $this->registry->runtime($slice);

        return new StoreHandle($slice, $runtime, $this->registry->writer());
    }
}
