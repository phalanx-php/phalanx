<?php

declare(strict_types=1);

namespace Phalanx\Server;

use Closure;
use OpenSwoole\Server;
use Phalanx\Registry\RegistryScope;

/**
 * Aegis-managed accessor over OpenSwoole\Server::stats().
 *
 * The master process tracks `connection_num`, `accept_count`, `close_count`,
 * `event_loop_lag`, etc. natively. This class exposes those counters as a
 * typed snapshot so callers (Stoa connection registries, admin endpoints,
 * the PressureMonitor) can ask Aegis for a server-wide read without
 * touching OpenSwoole APIs directly.
 *
 * Stoa is the typical injector: it constructs the ServerStats with the
 * live `OpenSwoole\Server` instance via {@see fromServer()} during boot.
 * Unit tests use {@see fromArray()} or {@see fromProvider()} to feed
 * synthetic stats without a live Server.
 *
 * The RegistryScope dimension is a forward-compatibility hook: today only
 * Server-wide reads come from stats(); per-worker reads are the consumer's
 * own per-worker registry size. The method exists so callers can route by
 * scope without conditional code at the callsite.
 */
final class ServerStats
{
    /**
     * @param Closure(): array<string, int|float|string> $statsProvider
     */
    public function __construct(private readonly Closure $statsProvider)
    {
    }

    public static function fromServer(Server $server): self
    {
        return new self(static function () use ($server): array {
            $raw = $server->stats();
            return is_array($raw) ? $raw : [];
        });
    }

    /**
     * @param array<string, int|float|string> $stats
     */
    public static function fromArray(array $stats): self
    {
        return new self(static fn(): array => $stats);
    }

    /**
     * @param Closure(): array<string, int|float|string> $provider
     */
    public static function fromProvider(Closure $provider): self
    {
        return new self($provider);
    }

    public function snapshot(): StatsSnapshot
    {
        return StatsSnapshot::fromStatsArray(($this->statsProvider)());
    }

    public function liveConnections(RegistryScope $scope): int
    {
        return match ($scope) {
            RegistryScope::Server => $this->snapshot()->connectionNum,
            RegistryScope::Worker => 0,
        };
    }
}
