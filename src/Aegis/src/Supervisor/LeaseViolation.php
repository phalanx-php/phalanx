<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

use Phalanx\Diagnostics\DiagnosticCode;
use RuntimeException;

/**
 * Thrown by Supervisor::registerLease() when a lease registration would
 * violate a Pool & Scope Discipline invariant. Each violation carries a
 * DiagnosticCode that maps to a specific section of the framework
 * documentation.
 *
 * @see DiagnosticCode::PoolNestedAcquire
 * @see DiagnosticCode::PoolCrossBoundary
 * @see DiagnosticCode::TransactionExternalIo
 * @see DiagnosticCode::LockOrderViolation
 * @see DiagnosticCode::LeaseOrphan
 */
final class LeaseViolation extends RuntimeException
{
    public function __construct(
        private(set) DiagnosticCode $diagnostic,
        private(set) string $detail,
        private(set) ?string $runId = null,
        private(set) ?string $runName = null,
        private(set) ?Lease $offending = null,
    ) {
        $context = $runId !== null && $runName !== null ? " (task '{$runName}', run {$runId})" : '';
        parent::__construct("[{$diagnostic->value}] {$detail}{$context}");
    }
}
