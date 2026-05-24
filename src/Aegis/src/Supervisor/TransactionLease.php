<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * A claim that the holding TaskRun is inside a database transaction. The
 * transaction body should not perform unrelated IO — external HTTP
 * calls, message publishes, etc. — while this lease is held; if the
 * transaction commits and the IO failed, retries become a correctness
 * problem. Detection of "external IO while holding a transaction" is
 * DiagnosticCode::TransactionExternalIo.
 *
 * Domain: pool name backing the transaction (e.g. "postgres/main").
 * Key:    transaction identifier (connection id + tx number).
 * Mode:   always 'exclusive'.
 */
final class TransactionLease implements Lease
{
    public string $mode {
        get => 'exclusive';
    }

    public function __construct(
        private(set) string $domain,
        private(set) string $key,
        private(set) float $acquiredAt = 0.0,
    ) {
    }

    public static function open(string $poolName, string $transactionId): self
    {
        return new self($poolName, $transactionId, microtime(true));
    }
}
