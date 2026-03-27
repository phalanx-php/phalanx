<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Dispatch;

enum DispatchStrategy
{
    case RoundRobin;
    case LeastMailbox;
}
