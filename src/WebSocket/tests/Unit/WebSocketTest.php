<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runner;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\WebSocket\Client;
use Phalanx\WebSocket\Client\Config as ClientConfig;
use Phalanx\WebSocket\Gateway;
use Phalanx\WebSocket\RouteGroup as WebSocketRouteGroup;
use Phalanx\WebSocket\Server\Upgrade;
use Phalanx\WebSocket\WebSocket;
use PHPUnit\Framework\Attributes\Test;

final class WebSocketTest extends PhalanxTestCase
{
    #[Test]
    public function servicesDoNotAdvertiseUnusedServerContext(): void
    {
        self::assertTrue(WebSocket::services()::harness()->isEmpty());
    }

    #[Test]
    public function servicesRegisterGatewayClientAndClientConfig(): void
    {
        $clientConfig = new ClientConfig(connectTimeout: 1.5);
        $bundle = WebSocket::services($clientConfig);

        $result = $this->testApp(bundles: $bundle)->scoped(
            Task::named(
                'test.websocket.service-bundle',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(ClientConfig::class);
                    $client = WebSocket::client($scope);

                    self::assertInstanceOf(Gateway::class, WebSocket::gateway($scope));
                    self::assertInstanceOf(Client::class, $client);
                    self::assertInstanceOf(ClientConfig::class, $resolvedConfig);

                    return [
                        'connectTimeout' => $resolvedConfig->connectTimeout,
                    ];
                },
            ),
        );

        self::assertSame([
            'connectTimeout' => 1.5,
        ], $result);
    }

    #[Test]
    public function servicesAreIdempotentAndKeepTheFirstConfiguration(): void
    {
        $first = new ClientConfig(connectTimeout: 7.5);
        $firstBundle = WebSocket::services($first);
        $secondBundle = WebSocket::services();

        $result = $this->testApp([], $firstBundle, $secondBundle)->scoped(
            Task::named(
                'test.websocket.idempotent-bundle',
                static fn(ExecutionScope $scope): float => $scope->service(ClientConfig::class)->connectTimeout,
            ),
        );

        self::assertSame(7.5, $result);
    }

    #[Test]
    public function installRegistersWebSocketUpgradeOnRunner(): void
    {
        $app = $this->testApp(bundles: WebSocket::services())->start()->hostForInternalTesting();
        $runner = Runner::from($app)->withRoutes(RouteGroup::of([]));

        self::assertNull($runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN));
        self::assertNotContains(WebSocket::UPGRADE_TOKEN, $runner->upgrades()->tokens());

        WebSocket::install($runner, $app, WebSocketRouteGroup::of([], new Gateway()));

        self::assertContains(WebSocket::UPGRADE_TOKEN, $runner->upgrades()->tokens());
        self::assertInstanceOf(
            Upgrade::class,
            $runner->upgrades()->resolve(WebSocket::UPGRADE_TOKEN),
        );
    }
}
