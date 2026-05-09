<?php

declare(strict_types=1);

namespace Phalanx\Actor;

enum StopStatus: string
{
    case Stopped = 'stopped';
    case AlreadyStopped = 'already_stopped';
    case Failed = 'failed';
}
