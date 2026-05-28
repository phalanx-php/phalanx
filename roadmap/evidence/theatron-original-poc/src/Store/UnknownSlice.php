<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

final class UnknownSlice extends StoreException
{
    public static function class(string $slice): self
    {
        return new self("Store slice {$slice} is not registered.");
    }
}
