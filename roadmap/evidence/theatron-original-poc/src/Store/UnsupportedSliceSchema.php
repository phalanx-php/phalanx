<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Store;

final class UnsupportedSliceSchema extends StoreException
{
    public static function property(string $slice, string $property, string $reason): self
    {
        return new self("Slice {$slice} property \${$property} is not storeable: {$reason}.");
    }

    public static function constructor(string $slice): self
    {
        return new self("Slice {$slice} must have a no-argument/default constructor.");
    }
}
