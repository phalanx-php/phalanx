<?php

declare(strict_types=1);

namespace Phalanx\Worker;

enum DispatchStrategy
{
    case RoundRobin;

    case LeastMailbox;
}
