<?php

declare(strict_types=1);

namespace Convoy\Task;

use UnitEnum;

interface UsesPool
{
    public UnitEnum $pool { get; }
}
