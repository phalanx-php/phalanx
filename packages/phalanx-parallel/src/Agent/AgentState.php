<?php

declare(strict_types=1);

namespace Phalanx\Parallel\Agent;

enum AgentState
{
    case Idle;
    case Processing;
    case Crashed;
    case Draining;
}
