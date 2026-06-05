<?php

declare(strict_types=1);

namespace Phalanx\Worker\Dispatch;

enum DispatchStrategy
{
    case RoundRobin;
    case LeastMailbox;
}
