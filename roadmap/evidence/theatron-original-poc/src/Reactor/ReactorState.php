<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

enum ReactorState: string
{
    case Idle = 'idle';
    case Running = 'running';
    case Restarting = 'restarting';
    case Crashed = 'crashed';
    case Cancelled = 'cancelled';
    case Exhausted = 'exhausted';
}
