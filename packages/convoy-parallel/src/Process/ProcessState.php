<?php

declare(strict_types=1);

namespace Convoy\Parallel\Process;

enum ProcessState
{
    case Idle;
    case Busy;
    case Crashed;
    case Draining;
}
