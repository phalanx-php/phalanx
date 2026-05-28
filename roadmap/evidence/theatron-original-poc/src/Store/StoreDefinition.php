<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

final class StoreDefinition
{
    /** @param list<class-string<Slice>> $slices */
    public function __construct(
        private(set) string $name,
        private(set) StoreStrategy $strategy,
        private(set) array $slices,
    ) {
        $this->slices = array_values(array_unique($this->slices));
    }
}
