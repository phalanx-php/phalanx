<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Slice;

enum ActivityStatus
{
    case Idle;
    case Running;
    case AwaitingApproval;
    case Completed;
    case Failed;
    case Cancelled;
}
