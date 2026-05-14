<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Unit;

use Phalanx\Application;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Hermes\Hermes;
use Phalanx\Hermes\Server\WsServerUpgrade;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsRouteGroup;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HermesFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterGatewayClientAndClientConfig(): void
    {
        $clientConfig = new WsClientConfig(connectTimeout: 1.5);
        $bundle = Hermes::services($clientConfig);

        $result = [];

        TestScope::compile(services: static fn($services, $context): mixed => $bundle->services($services, $context))
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $resolvedConfig = $scope->service(WsClientConfig::class);
                $client = Hermes::client($scope);

                self::assertInstanceOf(WsGateway::class, Hermes::gateway($scope));
                self::assertInstanceOf(WsClient::class, $client);
                self::assertInstanceOf(WsClientConfig::class, $resolvedConfig);

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
        $first = new WsClientConfig(connectTimeout: 7.5);
        $firstBundle = Hermes::services($first);
        $secondBundle = Hermes::services();

        $result = null;

        TestScope::compile(
            services: static function ($services, $context) use ($firstBundle, $secondBundle): void {
                $firstBundle->services($services, $context);
                $secondBundle->services($services, $context);
            },
        )
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $result = $scope->service(WsClientConfig::class)->connectTimeout;
            });

        self::assertSame(7.5, $result);
    }

    #[Test]
    public function installRegistersWebSocketUpgradeOnRunner(): void
    {
        $app = Application::starting()
            ->providers(Hermes::services())
            ->withLedger(new InProcessLedger())
            ->compile()
            ->startup();

        try {
            $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertNull($runner->upgrades()->resolve(Hermes::UPGRADE_TOKEN));
            self::assertNotContains(Hermes::UPGRADE_TOKEN, $runner->upgrades()->tokens());

            Hermes::install($runner, $app, WsRouteGroup::of([], new WsGateway()));

            self::assertContains(Hermes::UPGRADE_TOKEN, $runner->upgrades()->tokens());
            self::assertInstanceOf(
                WsServerUpgrade::class,
                $runner->upgrades()->resolve(Hermes::UPGRADE_TOKEN),
            );
        } finally {
            $app->shutdown();
        }
    }
}
