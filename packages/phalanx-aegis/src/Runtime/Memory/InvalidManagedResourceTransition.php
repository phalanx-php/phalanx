<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Memory;

final class InvalidManagedResourceTransition extends ManagedResourceException
{
    public static function forState(
        string $id,
        ManagedResourceState $from,
        ManagedResourceState $to,
    ): self {
        return new self("invalid managed resource transition for '{$id}': {$from->value} -> {$to->value}");
    }
}
