<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final class StaleManagedResourceHandle extends ManagedResourceException
{
    public static function forGeneration(string $id, int $expected, int $actual): self
    {
        return new self("stale managed resource handle for '{$id}': expected generation {$expected}, found {$actual}");
    }
}
