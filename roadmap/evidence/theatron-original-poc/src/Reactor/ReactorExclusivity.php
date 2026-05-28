<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Reactor;

enum ReactorExclusivity
{
    case Exclusive;
    case Concurrent;
}
