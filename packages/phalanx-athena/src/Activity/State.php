<?php

declare(strict_types=1);

namespace Phalanx\Athena\Activity;

enum State: string
{
    case Pending   = 'pending';
    case Running   = 'running';
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';
}
