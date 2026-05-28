<?php

declare(strict_types=1);

namespace AegisSwoole\Worker;

enum DispatchStrategy
{
    case RoundRobin;

    case LeastMailbox;
}
