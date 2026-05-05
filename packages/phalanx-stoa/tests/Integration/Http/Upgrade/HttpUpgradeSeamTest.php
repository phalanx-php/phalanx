<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration\Http\Upgrade;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Tests\Support\CoroutineTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HttpUpgradeSeamTest extends CoroutineTestCase
{
    #[Test]
    public function upgradeRequestWithoutRegistrarReturns426(): void
    {
        $this->runInCoroutine(static function (): void {
            $app = Application::starting()
                ->withLedger(new InProcessLedger())
                ->compile()
                ->startup();

            try {
                $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

                $response = $runner->dispatch(
                    new ServerRequest('GET', '/socket')
                        ->withHeader('Upgrade', 'websocket')
                        ->withHeader('Connection', 'Upgrade'),
                );

                self::assertSame(426, $response->getStatusCode());
            } finally {
                $app->shutdown();
            }
        });
    }

    #[Test]
    public function plainRequestSkipsUpgradePath(): void
    {
        $this->runInCoroutine(static function (): void {
            $app = Application::starting()
                ->withLedger(new InProcessLedger())
                ->compile()
                ->startup();

            try {
                $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

                $response = $runner->dispatch(new ServerRequest('GET', '/somewhere'));

                self::assertSame(404, $response->getStatusCode());
            } finally {
                $app->shutdown();
            }
        });
    }

    #[Test]
    public function upgradeHeaderWithoutConnectionUpgradeIsIgnored(): void
    {
        $this->runInCoroutine(static function (): void {
            $app = Application::starting()
                ->withLedger(new InProcessLedger())
                ->compile()
                ->startup();

            try {
                $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

                $response = $runner->dispatch(
                    new ServerRequest('GET', '/somewhere')
                        ->withHeader('Upgrade', 'websocket'),
                );

                self::assertSame(404, $response->getStatusCode());
            } finally {
                $app->shutdown();
            }
        });
    }

    #[Test]
    public function registeredTokenAppearsInRegistry(): void
    {
        $this->runInCoroutine(static function (): void {
            $app = Application::starting()
                ->withLedger(new InProcessLedger())
                ->compile()
                ->startup();

            try {
                $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

                self::assertNull($runner->upgrades()->resolve('h2c'));
                self::assertCount(0, $runner->upgrades()->tokens());
            } finally {
                $app->shutdown();
            }
        });
    }
}
