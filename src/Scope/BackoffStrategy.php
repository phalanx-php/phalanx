<?php

declare(strict_types=1);

namespace Phalanx\Scope;

enum BackoffStrategy
{
    case Fixed;
    case Linear;
    case Exponential;
}
