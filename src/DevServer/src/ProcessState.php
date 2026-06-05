<?php

declare(strict_types=1);

namespace Phalanx\DevServer;

enum ProcessState
{
    case Starting;
    case Running;
    case Crashed;
    case Stopped;
}
