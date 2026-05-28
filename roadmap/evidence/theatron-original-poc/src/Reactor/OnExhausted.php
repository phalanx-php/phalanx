<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

enum OnExhausted
{
    case Stop;
    case Escalate;
}
