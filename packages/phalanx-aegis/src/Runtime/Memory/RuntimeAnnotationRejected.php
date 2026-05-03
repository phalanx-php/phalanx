<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final class RuntimeAnnotationRejected extends ManagedResourceException
{
    public static function forKey(string $key): self
    {
        return new self("runtime annotation '{$key}' must be scalar, bounded, and namespaced");
    }
}
