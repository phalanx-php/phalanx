<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\Application;
use Phalanx\Registry\RegistryScope;
use Phalanx\Server\ServerStats;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StoaRunnerActiveRequestsTest extends TestCase
{
    #[Test]
    public function workerScopeReturnsLocalRegistrySize(): void
    {
        $app = Application::starting()->compile()->startup();

        try {
            $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertSame(0, $runner->activeRequests());
            self::assertSame(0, $runner->activeRequests(RegistryScope::Worker));
            self::assertSame([], $runner->activeRequestsByState());
            self::assertSame([], $runner->activeRequestsByState(RegistryScope::Worker));
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function serverScopeQueriesInjectedServerStats(): void
    {
        $app = Application::starting()->compile()->startup();

        try {
            $runner = StoaRunner::from($app)
                ->withRoutes(RouteGroup::of([]))
                ->withServerStats(ServerStats::fromArray([
                    'connection_num' => 17,
                    'accept_count' => 100,
                    'close_count' => 83,
                ]));

            self::assertSame(17, $runner->activeRequests(RegistryScope::Server));
            self::assertSame(0, $runner->activeRequests(RegistryScope::Worker));
            self::assertSame([], $runner->activeRequestsByState(RegistryScope::Server));
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function serverScopeFallsBackToWorkerCountWhenStatsAbsent(): void
    {
        $app = Application::starting()->compile()->startup();

        try {
            $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertSame(0, $runner->activeRequests(RegistryScope::Server));
        } finally {
            $app->shutdown();
        }
    }
}
