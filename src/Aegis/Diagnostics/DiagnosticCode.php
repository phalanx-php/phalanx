<?php

declare(strict_types=1);

namespace Phalanx\Diagnostics;

enum DiagnosticCode: string
{
    case PoolNestedAcquire = 'PHX-POOL-001';
    case PoolCrossBoundary = 'PHX-POOL-002';
    case PoolDoubleRelease = 'PHX-POOL-003';
    case PoolStarvation = 'PHX-POOL-004';
    case TransactionExternalIo = 'PHX-TXN-001';
    case LockOrderViolation = 'PHX-LOCK-001';
    case LeaseOrphan = 'PHX-LEASE-001';
    case SpawnError = 'PHX-SPAWN-001';
    case SpawnForceCancelled = 'PHX-SPAWN-002';
    case PressureEventLoopLag = 'PHX-PRESSURE-001';
    case RecoveryExhausted = 'PHX-RECOVERY-001';
    case CircuitOpen = 'PHX-CIRCUIT-001';
}
