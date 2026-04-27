<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Process;

enum ProcessState
{
    case Idle;
    case Busy;
    case Crashed;
    case Draining;
}
