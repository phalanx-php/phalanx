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
 * order between tasks would otherwise allow PHX-LOCK-001 deadlocks.
 */
final class LockLease implements Lease
{
    public string $domain {
        get => $this->lockDomain;
    }

    public string $key {
        get => $this->lockKey;
    }

    public string $mode {
        get => $this->lockMode;
    }

    public float $acquiredAt {
        get => $this->acquired;
    }

    public function __construct(
        public readonly string $lockDomain,
        public readonly string $lockKey,
        public readonly string $lockMode,
        public readonly float $acquired = 0.0,
    ) {
        if ($this->lockMode !== 'read' && $this->lockMode !== 'write') {
            throw new \InvalidArgumentException(
                "LockLease mode must be 'read' or 'write', got '{$this->lockMode}'.",
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
