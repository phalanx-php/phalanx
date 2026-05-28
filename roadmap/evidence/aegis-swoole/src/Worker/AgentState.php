<?php

declare(strict_types=1);

namespace AegisSwoole\Worker;

enum AgentState
{
    case Idle;

    case Processing;

    case Draining;

    case Crashed;
}
