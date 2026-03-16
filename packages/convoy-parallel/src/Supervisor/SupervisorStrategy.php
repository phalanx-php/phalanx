<?php

declare(strict_types=1);

namespace Convoy\Parallel\Supervisor;

enum SupervisorStrategy
{
    case Ignore;
    case StopAll;
    case RestartOnCrash;
}
