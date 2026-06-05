<?php

declare(strict_types=1);

namespace Phalanx\Scheduling;

enum TaskPriority: int
{
    case Low = -1;
    case Normal = 0;
    case High = 1;
    case Critical = 2;
}
