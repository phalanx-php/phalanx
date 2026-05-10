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
use Phalanx\Scope\ExecutionScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HermesFacadeTest extends TestCase
{
    #[Test]
    public function servicesRegisterGatewayClientAndClientConfig(): void
    {
        $clientConfig = new WsClientConfig(connectTimeout: 1.5);

        $result = Application::starting()
            ->providers(Hermes::services($clientConfig))
            ->run(Task::named(
                'test.hermes.facade.services',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(WsClientConfig::class);
                    $client = Hermes::client($scope);

                    self::assertInstanceOf(WsGateway::class, Hermes::gateway($scope));
                    self::assertInstanceOf(WsClient::class, $client);
                    self::assertInstanceOf(WsClientConfig::class, $resolvedConfig);

                    return [
                        'connectTimeout' => $resolvedConfig->connectTimeout,
                    ];
                },
            ));

        self::assertSame([
            'connectTimeout' => 1.5,
        ], $result);
    }

    #[Test]
    public function servicesAreIdempotentAndKeepTheFirstConfiguration(): void
    {
        $first = new WsClientConfig(connectTimeout: 7.5);

        $result = Application::starting()
            ->providers(Hermes::services($first), Hermes::services())
            ->run(Task::named(
                'test.hermes.facade.idempotent',
                static fn(ExecutionScope $scope): float => $scope->service(WsClientConfig::class)->connectTimeout,
            ));

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
