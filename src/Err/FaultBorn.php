<?php

declare(strict_types=1);

namespace Phalanx\Err;

/**
 * Conversion-map product: an Err the kernel constructs from the live Fault
 * when a faultsAs map arm matches. Construction runs only on the fault path.
 */
interface FaultBorn extends Err
{
    public static function fromFault(Fault $f): static;
}
