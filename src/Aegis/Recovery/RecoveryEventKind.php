<?php

declare(strict_types=1);

namespace Phalanx\Recovery;

enum RecoveryEventKind
{
    case AttemptStarted;
    case AttemptSucceeded;
    case AttemptFailed;
    case Timeout;
    case DeadlineReached;
    case Retry;
    case CircuitOpen;
    case CircuitHalfOpen;
    case CircuitClosed;
    case PollWait;
}
