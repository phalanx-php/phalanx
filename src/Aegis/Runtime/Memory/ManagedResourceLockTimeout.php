<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final class ManagedResourceLockTimeout extends ManagedResourceException
{
    public static function forResource(string $id, float $timeout): self
    {
        return new self("timed out after {$timeout}s waiting for managed resource '{$id}'");
    }
}
