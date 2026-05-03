<?php

declare(strict_types=1);

namespace Phalanx\Runtime\Identity;

enum AegisEventSid: string implements RuntimeEventId
{
    case ResourceEdge = 'resource.edge';
    case ResourceLateTransition = 'resource.late_transition';
    case ResourceLeaseAcquired = 'resource.lease_acquired';
    case ResourceLeaseReleased = 'resource.lease_released';
    case ResourceOpened = 'resource.opened';
    case ResourceReleased = 'resource.released';
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
