<?php

declare(strict_types=1);

namespace Phalanx\Worker\Supervisor;

enum SupervisorStrategy: string
{
    case Ignore = 'ignore';
    case StopAll = 'stop_all';
    case RestartOnCrash = 'restart_on_crash';
}
