<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\Application;
use Phalanx\WebSocket\Client\WsClient;
use Phalanx\WebSocket\Client\WsClientConfig;
use Phalanx\WebSocket\WebSocket;
use Phalanx\WebSocket\Server\WsServerUpgrade;
use Phalanx\WebSocket\WsGateway;
use Phalanx\WebSocket\WsRouteGroup;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\HttpRunner;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Testing\TestScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebSocketFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterGatewayClientAndClientConfig(): void
    {
        $clientConfig = new WsClientConfig(connectTimeout: 1.5);
        $bundle = WebSocket::services($clientConfig);

        $result = [];

        TestScope::compile(services: static fn($services, $context): mixed => $bundle->services($services, $context))
            ->shutdownAfterRun()
            ->run(static function ($scope) use (&$result): void {
                $resolvedConfig = $scope->service(WsClientConfig::class);
                $client = WebSocket::client($scope);

                self::assertInstanceOf(WsGateway::class, WebSocket::gateway($scope));
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
        $firstBundle = WebSocket::services($first);
        $secondBundle = WebSocket::services();

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
            ->providers(WebSocket::services())
            ->withLedger(new InProcessLedger())
            ->compile()
            ->startup();

        try {
            $runner = HttpRunner::from($app)->withRoutes(RouteGroup::of([]));

            self::assertNull($runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN));
            self::assertNotContains(WebSocket::UPGRADE_TOKEN, $runner->upgrades()->tokens());

            WebSocket::install($runner, $app, WsRouteGroup::of([], new WsGateway()));

            self::assertContains(WebSocket::UPGRADE_TOKEN, $runner->upgrades()->tokens());
            self::assertInstanceOf(
                WsServerUpgrade::class,
                $runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN),
            );
        } finally {
            $app->shutdown();
        }
    }
}
