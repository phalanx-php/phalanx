<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Plans;

enum WorkItemStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Done = 'done';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Superseded = 'superseded';
}
