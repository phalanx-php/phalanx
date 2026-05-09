<?php

declare(strict_types=1);

namespace Phalanx\Actor;

enum JoinState: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
