<?php

declare(strict_types=1);

namespace Phalanx\Skopos;

enum ProcessState
{
    case Starting;
    case Ready;
    case Running;
    case Crashed;
    case Stopped;
}
