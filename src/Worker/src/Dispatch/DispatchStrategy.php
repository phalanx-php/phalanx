<?php

declare(strict_types=1);

namespace Phalanx\Worker\Dispatch;

enum DispatchStrategy: string
{
    case RoundRobin = 'round_robin';
    case LeastMailbox = 'least_mailbox';
}
