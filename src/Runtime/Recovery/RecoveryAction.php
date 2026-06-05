<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

enum RecoveryAction
{
    case Continue;
    case Retry;
    case Delay;
    case Poll;
    case Cancel;
    case Fail;
}
