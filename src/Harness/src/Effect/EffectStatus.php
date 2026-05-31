<?php

declare(strict_types=1);

namespace Phalanx\Harness\Effect;

enum EffectStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Denied = 'denied';
    case Executing = 'executing';
    case Resolved = 'resolved';
    case Failed = 'failed';
}
