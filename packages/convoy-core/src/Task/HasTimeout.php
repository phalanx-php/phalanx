<?php

declare(strict_types=1);

namespace Convoy\Task;

interface HasTimeout
{
    public float $timeout { get; }
}
