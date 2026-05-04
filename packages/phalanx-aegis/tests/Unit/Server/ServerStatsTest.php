<?php

declare(strict_types=1);

namespace Phalanx\Tests\Unit\Server;

use Phalanx\Registry\RegistryScope;
use Phalanx\Server\ServerStats;
use PHPUnit\Framework\TestCase;

/**
 * ServerStats wraps a stats provider closure so production injects a live
 * OpenSwoole\Server (via fromServer) and tests inject a static array (via
 * fromArray). The snapshot reads through to the provider on every call so
 * downstream consumers see live counters.
 */
final class ServerStatsTest extends TestCase
{
    public function testFromArrayProducesMatchingSnapshot(): void
    {
        $stats = ServerStats::fromArray([
            'connection_num' => 7,
            'event_loop_lag' => 12_000,
        ]);

        $snapshot = $stats->snapshot();

        self::assertSame(7, $snapshot->connectionNum);
        self::assertSame(12.0, $snapshot->eventLoopLagMs);
    }

    public function testLiveConnectionsServerScopeReturnsConnectionNum(): void
    {
        $stats = ServerStats::fromArray(['connection_num' => 42]);

        self::assertSame(42, $stats->liveConnections(RegistryScope::Server));
    }

    public function testLiveConnectionsWorkerScopeReturnsZero(): void
    {
        $stats = ServerStats::fromArray(['connection_num' => 42]);

        // Worker-scope queries are answered by the consumer's own
        // per-worker registry, not by Aegis. Returning 0 is the contract.
        self::assertSame(0, $stats->liveConnections(RegistryScope::Worker));
    }

    public function testFromProviderRereadsOnEachSnapshot(): void
    {
        $count = 0;
        $stats = ServerStats::fromProvider(static function () use (&$count): array {
            $count++;
            return ['connection_num' => $count];
        });

        self::assertSame(1, $stats->snapshot()->connectionNum);
        self::assertSame(2, $stats->snapshot()->connectionNum);
        self::assertSame(3, $stats->snapshot()->connectionNum);
    }
}
