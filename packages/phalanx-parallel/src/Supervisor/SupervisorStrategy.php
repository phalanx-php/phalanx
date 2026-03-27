<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Supervisor;

enum SupervisorStrategy
{
    case Ignore;
    case StopAll;
    case RestartOnCrash;
}
