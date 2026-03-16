<?php

declare(strict_types=1);

namespace Convoy\Task;

interface HasPriority
{
    public int $priority { get; }
}
