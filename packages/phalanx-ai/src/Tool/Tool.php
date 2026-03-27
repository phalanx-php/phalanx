<?php

declare(strict_types=1);

namespace Phalanx\Ai\Tool;

use Phalanx\Scope;
use Phalanx\Task\Scopeable;

interface Tool extends Scopeable
{
    public string $description { get; }
}
