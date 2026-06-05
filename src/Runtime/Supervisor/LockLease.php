<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * A claim on a named lock held by a TaskRun. The lock registry is owned
 * by the supervisor; user code expresses intent via a write/read-lock
 * helper rather than touching channels or mutexes directly.
 *
 * Domain: lock domain (e.g. "cache", "queue", "filesystem").
 * Key:    lock key within the domain (e.g. "user:42", "/tmp/foo.lock").
 * Mode:   'read' | 'write'. Multiple read leases on the same key
 *         coexist; a write lease excludes everything else on the same
 *         key.
 *
 * Multi-key acquire MUST sort canonical keys before acquisition so the
 * acquisition order is deterministic across all task runs — circular
 * order between tasks would otherwise allow DiagnosticCode::LockOrderViolation deadlocks.
 */
final class LockLease implements Lease
{
    public function __construct(
        private(set) string $domain,
        private(set) string $key,
        private(set) string $mode,
        private(set) float $acquiredAt = 0.0,
    ) {
        if ($this->mode !== 'read' && $this->mode !== 'write') {
            throw new \InvalidArgumentException(
                "LockLease mode must be 'read' or 'write', got '{$this->mode}'.",
            );
        }
    }

    public static function read(string $domain, string $key): self
    {
        return new self($domain, $key, 'read', microtime(true));
    }

    public static function write(string $domain, string $key): self
    {
        return new self($domain, $key, 'write', microtime(true));
    }
}
