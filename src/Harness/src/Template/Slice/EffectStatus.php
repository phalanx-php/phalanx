<?php

declare(strict_types=1);

namespace Phalanx\Harness\Template\Slice;

enum EffectStatus: string
{
    case Requested = 'requested';
    case Paused = 'paused';
    case Approved = 'approved';
    case Denied = 'denied';
    case Executed = 'executed';
    case Failed = 'failed';
}
