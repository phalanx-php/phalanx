<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Supervisor;

enum SupervisorStrategy
{
    case Ignore;
    case StopAll;
    case RestartOnCrash;
}
