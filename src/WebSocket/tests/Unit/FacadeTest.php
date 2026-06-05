<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\Application;
use Phalanx\Http\RouteGroup;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterGatewayClientAndClientConfig(): void
    {
        $clientConfig = new \Phalanx\WebSocket\Client\Config(connectTimeout: 1.5);
        $bundle = \Phalanx\WebSocket\Facade::services($clientConfig);

        $result = [];

        TestScope::compile(services: static function ($services, $context) use ($bundle): void { $bundle->services($services, $context); })
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $resolvedConfig = $scope->service(\Phalanx\WebSocket\Client\Config::class);
                $client = \Phalanx\WebSocket\Facade::client($scope);

                self::assertInstanceOf(\Phalanx\WebSocket\Gateway::class, \Phalanx\WebSocket\Facade::gateway($scope));
                self::assertInstanceOf(\Phalanx\WebSocket\Client::class, $client);
                self::assertInstanceOf(\Phalanx\WebSocket\Client\Config::class, $resolvedConfig);

                $result = [
                    'connectTimeout' => $resolvedConfig->connectTimeout,
                ];
            });

        self::assertSame([
            'connectTimeout' => 1.5,
        ], $result);
    }

    #[Test]
    public function servicesAreIdempotentAndKeepTheFirstConfiguration(): void
    {
        $first = new \Phalanx\WebSocket\Client\Config(connectTimeout: 7.5);
        $firstBundle = \Phalanx\WebSocket\Facade::services($first);
        $secondBundle = \Phalanx\WebSocket\Facade::services();

        $result = null;

        TestScope::compile(
            services: static function ($services, $context) use ($firstBundle, $secondBundle): void {
                $firstBundle->services($services, $context);
                $secondBundle->services($services, $context);
            },
        )
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $result = $scope->service(\Phalanx\WebSocket\Client\Config::class)->connectTimeout;
            });

        self::assertSame(7.5, $result);
    }

    #[Test]
    public function installRegistersWebSocketUpgradeOnRunner(): void
    {
        $app = Application::starting()
            ->providers(\Phalanx\WebSocket\Facade::services())
            ->withLedger(new InProcessLedger())
            ->compile()
            ->startup();

        try {
            $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertNull($runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN));
            self::assertNotContains(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN, $runner->upgrades()->tokens());

            \Phalanx\WebSocket\Facade::install($runner, $app, \Phalanx\WebSocket\RouteGroup::of([], new \Phalanx\WebSocket\Gateway()));

            self::assertContains(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN, $runner->upgrades()->tokens());
            self::assertInstanceOf(
                \Phalanx\WebSocket\Server\Upgrade::class,
                $runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN),
            );
        } finally {
            $app->shutdown();
        }
    }
}
