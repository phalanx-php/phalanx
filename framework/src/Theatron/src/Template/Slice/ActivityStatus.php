<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Template\Slice;

enum ActivityStatus
{
    case Idle;
    case Running;
    case AwaitingApproval;
    case Completed;
    case Failed;
    case Cancelled;
}
