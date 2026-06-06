<?php

declare(strict_types=1);

namespace Phalanx\Tui\Runtime\Effects;

enum EffectStatus: string
{
    case Denied = 'denied';
    case Failed = 'failed';
    case Approved = 'approved';
    case Resolved = 'resolved';
    case Executing = 'executing';
    case Requested = 'requested';
}
