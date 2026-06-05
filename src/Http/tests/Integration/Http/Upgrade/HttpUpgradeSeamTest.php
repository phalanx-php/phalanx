<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Integration\Http\Upgrade;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Http\HttpRunner;
use Phalanx\Http\RouteGroup;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HttpUpgradeSeamTest extends PhalanxTestCase
{
    #[Test]
    public function upgradeRequestWithoutRegistrarReturns426(): void
    {
        $app = $this->startedApplication();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $runner = HttpRunner::from($app)->withRoutes(RouteGroup::of([]));

            $response = $runner->dispatch(
                new ServerRequest('GET', '/socket')
                    ->withHeader('Upgrade', 'websocket')
                    ->withHeader('Connection', 'Upgrade'),
            );

            self::assertSame(426, $response->getStatusCode());
        });
    }

    #[Test]
    public function plainRequestSkipsUpgradePath(): void
    {
        $app = $this->startedApplication();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $runner = HttpRunner::from($app)->withRoutes(RouteGroup::of([]));

            $response = $runner->dispatch(new ServerRequest('GET', '/somewhere'));

            self::assertSame(404, $response->getStatusCode());
        });
    }

    #[Test]
    public function upgradeHeaderWithoutConnectionUpgradeIsIgnored(): void
    {
        $app = $this->startedApplication();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $runner = HttpRunner::from($app)->withRoutes(RouteGroup::of([]));

            $response = $runner->dispatch(
                new ServerRequest('GET', '/somewhere')
                    ->withHeader('Upgrade', 'websocket'),
            );

            self::assertSame(404, $response->getStatusCode());
        });
    }

    #[Test]
    public function registeredTokenAppearsInRegistry(): void
    {
        $app = $this->startedApplication();

        $this->scope->run(static function (ExecutionScope $_scope) use ($app): void {
            $runner = HttpRunner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertNull($runner->upgrades()->resolve('h2c'));
            self::assertCount(0, $runner->upgrades()->tokens());
        });
    }

}
