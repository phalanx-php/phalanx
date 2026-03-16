<?php

declare(strict_types=1);

namespace Convoy\Parallel\Dispatch;

enum DispatchStrategy
{
    case RoundRobin;
    case LeastMailbox;
}
