<?php

declare(strict_types=1);

namespace Phalanx\Worker\Supervisor;

enum SupervisorStrategy
{
    case Ignore;
    case StopAll;
    case RestartOnCrash;
}
