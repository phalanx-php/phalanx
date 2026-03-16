<?php

declare(strict_types=1);

namespace Convoy\Parallel\Agent;

enum AgentState
{
    case Idle;
    case Processing;
    case Crashed;
    case Draining;
}
