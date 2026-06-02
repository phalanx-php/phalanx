<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\AppHost;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Hermes\Server\WsServerUpgrade;
use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;
use Phalanx\Stoa\StoaRunner;

final class Hermes
{
    public const string UPGRADE_TOKEN = 'websocket';

    private function __construct()
    {
    }

    public static function services(?WsClientConfig $clientConfig = null): ServiceBundle
    {
        return new WsServiceBundle($clientConfig);
    }

    public static function client(Scope $scope): WsClient
    {
        return $scope->service(WsClient::class);
    }

    public static function gateway(Scope $scope): WsGateway
    {
        return $scope->service(WsGateway::class);
    }

    /**
     * Wire Hermes's WebSocket upgradeable into a StoaRunner.
     *
     * Call after the app is compiled and routes are attached:
     *
     * ```php
     * $app = Application::starting()->providers(Hermes::services())->compile()->startup();
     * $runner = StoaRunner::from($app)->withRoutes($routes);
     * Hermes::install($runner, $app, WsRouteGroup::of([...]));
     * ```
     */
    public static function install(StoaRunner $runner, AppHost $app, WsRouteGroup $routes): void
    {
        $runner->upgrades()->register(
            self::UPGRADE_TOKEN,
            new WsServerUpgrade($app, $routes, $routes->gateway()),
        );
    }
}
