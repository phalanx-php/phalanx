<?php

declare(strict_types=1);

namespace Phalanx\Tui\Collab\Lifecycle;

enum LoopStage: string
{
    case React = 'react';
    case Review = 'review';
    case Receive = 'receive';
    case Prepare = 'prepare';
    case Execute = 'execute';
    case Complete = 'complete';
    case Distribute = 'distribute';
}
