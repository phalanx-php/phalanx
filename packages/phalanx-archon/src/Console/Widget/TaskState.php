<?php

declare(strict_types=1);

namespace Phalanx\Archon\Console\Widget;

enum TaskState
{
    case Pending;
    case Running;
    case Success;
    case Error;
    case Skipped;
}
