<?php

declare(strict_types=1);

namespace Convoy\Task;

interface Traceable
{
    public string $traceName { get; }
}
