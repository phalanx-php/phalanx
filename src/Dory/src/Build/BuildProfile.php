<?php

declare(strict_types=1);

namespace Phalanx\Dory\Build;

enum BuildProfile: string
{
    case Mini = 'mini';
    case Ops = 'ops';
    case Brain = 'brain';
    case Full = 'full';
    case Custom = 'custom';
}
