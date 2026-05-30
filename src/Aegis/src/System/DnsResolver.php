<?php

declare(strict_types=1);

namespace Phalanx\System;

use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use Swoole\Coroutine\System;

/**
 * Aegis-managed DNS resolver primitive.
 *
 * Uses getaddrinfo (not dnsLookup) so /etc/hosts entries resolve correctly.
 */
final class DnsResolver
{
    public function __construct(
        private(set) float $defaultTimeout = 5.0,
    ) {
    }

    private static function stripPort(string $hostOrHostPort): string
    {
        $lastColon = strrpos($hostOrHostPort, ':');

        if ($lastColon === false) {
            return $hostOrHostPort;
        }

        if (str_contains($hostOrHostPort, '[')) {
            $bracket = strrpos($hostOrHostPort, ']');
            return ($bracket !== false && $lastColon > $bracket)
                ? substr($hostOrHostPort, 1, $bracket - 1)
                : trim($hostOrHostPort, '[]');
        }

        return substr($hostOrHostPort, 0, $lastColon);
    }

    public function resolve(Suspendable $scope, string $hostname, ?float $timeout = null): DnsLookupResult
    {
        $result = $this->resolveAll($scope, $hostname, timeout: $timeout);
        $first = $result->first();

        return new DnsLookupResult(
            hostname: $result->hostname,
            durationMs: $result->durationMs,
            addresses: $first !== null ? [$first] : [],
        );
    }

    public function resolveAll(
        Suspendable $scope,
        string $hostname,
        int $family = AF_INET,
        ?float $timeout = null,
    ): DnsLookupResult {
        $host = self::stripPort($hostname);
        $effectiveTimeout = $timeout ?? $this->defaultTimeout;
        $startNs = hrtime(true);

        $entries = $scope->call(
            static fn(): array|false => System::getaddrinfo(
                $host,
                $family,
                SOCK_STREAM,
                STREAM_IPPROTO_TCP,
                '',
                $effectiveTimeout,
            ),
            WaitReason::custom("dns.resolveAll {$host}"),
        );

        $duration = (hrtime(true) - $startNs) / 1_000_000;
        $addresses = $entries === false
            ? []
            : array_values(array_filter($entries, static fn(mixed $v): bool => is_string($v)));

        return new DnsLookupResult(
            hostname: $host,
            addresses: $addresses,
            family: $family,
            durationMs: $duration,
        );
    }
}
