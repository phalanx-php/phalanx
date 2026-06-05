<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use Phalanx\Http\RouteGroup;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class FacadeTest extends PhalanxTestCase
{
    #[Test]
    public function servicesRegisterGatewayClientAndClientConfig(): void
    {
        $clientConfig = new \Phalanx\WebSocket\Client\Config(connectTimeout: 1.5);
        $bundle = \Phalanx\WebSocket\Facade::services($clientConfig);

        $result = $this->testApp(bundles: $bundle)->application->scoped(
            Task::named(
                'test.websocket.service-bundle',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(\Phalanx\WebSocket\Client\Config::class);
                    $client = \Phalanx\WebSocket\Facade::client($scope);

                    self::assertInstanceOf(\Phalanx\WebSocket\Gateway::class, \Phalanx\WebSocket\Facade::gateway($scope));
                    self::assertInstanceOf(\Phalanx\WebSocket\Client::class, $client);
                    self::assertInstanceOf(\Phalanx\WebSocket\Client\Config::class, $resolvedConfig);

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
        $first = new \Phalanx\WebSocket\Client\Config(connectTimeout: 7.5);
        $firstBundle = \Phalanx\WebSocket\Facade::services($first);
        $secondBundle = \Phalanx\WebSocket\Facade::services();

        $result = $this->testApp([], $firstBundle, $secondBundle)->application->scoped(
            Task::named(
                'test.websocket.idempotent-bundle',
                static fn(ExecutionScope $scope): float => $scope->service(\Phalanx\WebSocket\Client\Config::class)->connectTimeout,
            ),
        );

        self::assertSame(7.5, $result);
    }

    #[Test]
    public function installRegistersWebSocketUpgradeOnRunner(): void
    {
        $app = $this->startedApplication(bundles: \Phalanx\WebSocket\Facade::services());
        $runner = \Phalanx\Http\Runner::from($app)->withRoutes(RouteGroup::of([]));

        self::assertNull($runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN));
        self::assertNotContains(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN, $runner->upgrades()->tokens());

        \Phalanx\WebSocket\Facade::install($runner, $app, \Phalanx\WebSocket\RouteGroup::of([], new \Phalanx\WebSocket\Gateway()));

        self::assertContains(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN, $runner->upgrades()->tokens());
        self::assertInstanceOf(
            \Phalanx\WebSocket\Server\Upgrade::class,
            $runner->upgrades()->resolve(\Phalanx\WebSocket\Facade::UPGRADE_TOKEN),
        );
    }
}
