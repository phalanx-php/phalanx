<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit;

use Phalanx\Http\RouteGroup;
use Phalanx\Registry\RegistryScope;
use Phalanx\Server\ServerStats;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HttpRunnerActiveRequestsTest extends PhalanxTestCase
{
    #[Test]
    public function workerScopeReturnsLocalRegistrySize(): void
    {
        $app = $this->testApp()->start()->hostForInternalTesting();
        $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([]));

        self::assertSame(0, $runner->activeRequests());
        self::assertSame(0, $runner->activeRequests(RegistryScope::Worker));
        self::assertSame([], $runner->activeRequestsByState());
        self::assertSame([], $runner->activeRequestsByState(RegistryScope::Worker));
    }

    #[Test]
    public function serverScopeQueriesInjectedServerStats(): void
    {
        $app = $this->testApp()->start()->hostForInternalTesting();
        $runner = \Phalanx\Http\Runner::from($app)
            ->withRoutes(RouteGroup::of([]))
            ->withServerStats(ServerStats::fromArray([
                'connection_num' => 17,
                'accept_count' => 100,
                'close_count' => 83,
            ]));

        self::assertSame(17, $runner->activeRequests(RegistryScope::Server));
        self::assertSame(0, $runner->activeRequests(RegistryScope::Worker));
        self::assertSame([], $runner->activeRequestsByState(RegistryScope::Server));
    }

    #[Test]
    public function serverScopeFallsBackToWorkerCountWhenStatsAbsent(): void
    {
        $app = $this->testApp()->start()->hostForInternalTesting();
        $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([]));

        self::assertSame(0, $runner->activeRequests(RegistryScope::Server));
    }
}
