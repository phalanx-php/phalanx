<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * Lifecycle state of a TaskRun in the supervisor's ledger.
 *
 * Pending    Run record exists but the body has not started.
 * Running    Body is executing in its coroutine; not currently parked on a wait.
 * Suspended  Body is parked on a wait point recorded as a WaitReason.
 * Completed  Body returned normally; reap pending.
 * Failed     Body threw a non-Cancelled throwable; reap pending.
 * Cancelled  Body was cancelled cooperatively (parent cancel, timeout, race loser, explicit).
 */
enum RunState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Suspended = 'suspended';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            self::Pending, self::Running, self::Suspended => false,
        };
    }
}
