<?php

declare(strict_types=1);

namespace Phalanx\Task;

use UnitEnum;

interface UsesPool
{
    public UnitEnum $pool { get; }
}
