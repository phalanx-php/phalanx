<?php

declare(strict_types=1);

namespace Phalanx\Theatron\Collab\Lifecycle;

enum LoopStage: string
{
    case Receive = 'receive';
    case Prepare = 'prepare';
    case Distribute = 'distribute';
    case Execute = 'execute';
    case React = 'react';
    case Review = 'review';
    case Complete = 'complete';
}
