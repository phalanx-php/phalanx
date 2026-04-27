<?php

declare(strict_types=1);

namespace Phalanx\Task;

interface HasPriority
{
    public int $priority { get; }
}
