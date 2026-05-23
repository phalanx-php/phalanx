<?php

declare(strict_types=1);

namespace Phalanx\Task;

interface HasTimeout
{
    public float $timeout { get; }
}
