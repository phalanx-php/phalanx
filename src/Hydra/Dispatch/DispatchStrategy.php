<?php

declare(strict_types=1);

namespace Phalanx\Hydra\Dispatch;

enum DispatchStrategy
{
    case RoundRobin;
    case LeastMailbox;
}
