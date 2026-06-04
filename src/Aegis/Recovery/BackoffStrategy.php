<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

enum BackoffStrategy
{
    case Fixed;
    case Linear;
    case Exponential;
}
