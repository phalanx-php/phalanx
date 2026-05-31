<?php

declare(strict_types=1);

namespace Phalanx\Harness\Loop;

enum LoopStage: string
{
    case Receive = 'receive';
    case Prepare = 'prepare';
    case Distribute = 'distribute';
    case Collaborate = 'collaborate';
    case React = 'react';
    case Review = 'review';
    case Complete = 'complete';
}
