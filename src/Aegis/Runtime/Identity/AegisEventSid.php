<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum AegisEventSid: string implements RuntimeEventId
{
    case ProcessExited = 'process.exited';
    case ProcessKilled = 'process.killed';
    case ProcessReadFailed = 'process.read_failed';
    case ProcessStarted = 'process.started';
    case ProcessStopped = 'process.stopped';
    case ProcessWriteFailed = 'process.write_failed';
    case ResourceEdge = 'resource.edge';
    case ResourceLateTransition = 'resource.late_transition';
    case ResourceLeaseAcquired = 'resource.lease_acquired';
    case ResourceLeaseReleased = 'resource.lease_released';
    case ResourceOpened = 'resource.opened';
    case ResourceReleased = 'resource.released';
    case ResourceUpgraded = 'resource.upgraded';
    case RunResumed = 'run.resumed';
    case RunRunning = 'run.running';
    case RunSuspended = 'run.suspended';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
