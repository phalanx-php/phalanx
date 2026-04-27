<?php

declare(strict_types=1);

namespace Phalanx\Task;

interface Traceable
{
    public string $traceName { get; }
}
