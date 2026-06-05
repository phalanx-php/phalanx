<?php

declare(strict_types=1);

namespace Phalanx\Worker\Process;

enum WorkerState
{
    case Idle;
    case Processing;
    case Crashed;
    case Draining;
}
