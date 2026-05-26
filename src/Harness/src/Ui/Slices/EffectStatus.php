<?php

declare(strict_types=1);

namespace Phalanx\Harness\Ui\Slices;

enum EffectStatus: string
{
    case Requested = 'requested';
    case Paused = 'paused';
    case Approved = 'approved';
    case Denied = 'denied';
    case Executed = 'executed';
    case Failed = 'failed';
}
