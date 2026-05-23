<?php

declare(strict_types=1);

namespace Phalanx\System;

use OpenSwoole\Coroutine\System;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;

/**
 * Aegis-managed DNS resolver primitive.
 *
 * Wraps OpenSwoole\Coroutine\System::dnsLookup and getaddrinfo under
 * the scope's supervised call() so cancellation flows through scope
 * teardown and the supervisor records the wait reason. Downstream
 * consumers (Argos ResolveHostname, future Hermes outbound, agent
 * runtimes that pre-resolve hosts) share one coroutine-aware DNS path.
 */
final readonly class DnsResolver
{
    public function __construct(
        public float $defaultTimeout = 5.0,
    ) {
    }

    public function resolve(Suspendable $scope, string $hostname, ?float $timeout = null): DnsLookupResult
    {
        $effectiveTimeout = $timeout ?? $this->defaultTimeout;
        $startNs = hrtime(true);

        $address = $scope->call(
            static fn(): string|false => System::dnsLookup($hostname, $effectiveTimeout),
            WaitReason::custom("dns.resolve {$hostname}"),
        );

        $duration = (hrtime(true) - $startNs) / 1_000_000;

        return new DnsLookupResult(
            hostname: $hostname,
            addresses: $address === false ? [] : [$address],
            durationMs: $duration,
        );
    }

    public function resolveAll(
        Suspendable $scope,
        string $hostname,
        int $family = AF_INET,
        ?float $timeout = null,
    ): DnsLookupResult {
        $effectiveTimeout = $timeout ?? $this->defaultTimeout;
        $startNs = hrtime(true);

        $entries = $scope->call(
            static fn(): array|false => System::getaddrinfo(
                $hostname,
                $family,
                SOCK_STREAM,
                STREAM_IPPROTO_TCP,
                '',
                $effectiveTimeout,
            ),
            WaitReason::custom("dns.resolveAll {$hostname}"),
        );

        $duration = (hrtime(true) - $startNs) / 1_000_000;
        $addresses = $entries === false
            ? []
            : array_values(array_filter($entries, static fn(mixed $v): bool => is_string($v)));

        return new DnsLookupResult(
            hostname: $hostname,
            addresses: $addresses,
            family: $family,
            durationMs: $duration,
        );
    }
}
