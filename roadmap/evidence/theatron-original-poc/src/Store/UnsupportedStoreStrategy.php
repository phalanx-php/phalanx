<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

final class UnsupportedStoreStrategy extends StoreException
{
    public static function strategy(StoreStrategy $strategy): self
    {
        return new self("Store strategy {$strategy->name} is not implemented in this Theatron slice.");
    }
}
