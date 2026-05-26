<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

enum ConversationTurnStatus: string
{
    case Running = 'running';
    case AwaitingApproval = 'awaiting-approval';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
