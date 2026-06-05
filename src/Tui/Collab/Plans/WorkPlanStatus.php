<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Plans;

enum WorkPlanStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Complete = 'complete';
    case Aborted = 'aborted';
}
