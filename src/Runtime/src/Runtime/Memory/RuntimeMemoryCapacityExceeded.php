<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final class RuntimeMemoryCapacityExceeded extends ManagedResourceException
{
    public static function forTable(string $table, string $id): self
    {
        return new self("runtime memory table '{$table}' is full; failed to write '{$id}'");
    }
}
