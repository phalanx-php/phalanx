<?php

declare(strict_types=1);

namespace Phalanx\Supervisor;

/**
 * A claim on an in-flight network write held by a TaskRun until the
 * underlying kernel send buffer drains.
 *
 * Producers (Stoa SSE writers, WebSocket frame senders, raw TCP/UDP
 * emitters) register a DeliveryLease at write time and release it via
 * `OpenSwoole\Server::onBufferEmpty($fd)`. While the lease is held the
 * supervisor knows the task is parked on a buffered write — distinct
 * from a connection-pool checkout (PoolLease) or a transactional hold
 * (TransactionLease).
 *
 * Domain: write surface name (e.g. "sse-stream", "ws-frame", "udp-broadcast").
 * Key:    the file-descriptor (fd) of the underlying socket, stringified.
 * Mode:   'flush' — release fires when the kernel buffer drains.
 *
 * The lease is recorded in the same `resource_leases` table as the other
 * lease families so existing test expectations (LeaseExpectation::released)
 * cover the new family without a parallel ledger.
 */
final class DeliveryLease implements Lease
{
    public string $domain {
        get => $this->surfaceDomain;
    }

    public string $key {
        get => $this->fdKey;
    }

    public string $mode {
        get => 'flush';
    }

    public float $acquiredAt {
        get => $this->acquired;
    }

    public function __construct(
        public readonly string $surfaceDomain,
        public readonly string $fdKey,
        public readonly float $acquired = 0.0,
    ) {
    }

    /**
     * Open a lease for an in-flight write on `$fd` belonging to the named
     * `$domain` (typically a write-surface label like "sse-stream").
     */
    public static function open(string $domain, int $fd): self
    {
        return new self($domain, (string) $fd, microtime(true));
    }
}
