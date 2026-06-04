<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

enum CircuitState
{
    case Closed;
    case Open;
    case HalfOpen;
}
