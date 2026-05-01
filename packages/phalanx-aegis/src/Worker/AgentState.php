<?php

declare(strict_types=1);

namespace Phalanx\Worker;

enum AgentState
{
    case Idle;

    case Processing;

    case Draining;

    case Crashed;
}
