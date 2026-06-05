<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\AppHost;
use Phalanx\Http\HttpRunner;
use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;
use Phalanx\WebSocket\Client\WsClient;
use Phalanx\WebSocket\Client\WsClientConfig;
use Phalanx\WebSocket\Server\WsServerUpgrade;

final class WebSocket
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
     * Wire WebSocket's WebSocket upgradeable into a HttpRunner.
     *
     * Call after the app is compiled and routes are attached:
     *
     * ```php
     * $app = Application::starting()->providers(WebSocket::services())->compile()->startup();
     * $runner = HttpRunner::from($app)->withRoutes($routes);
     * WebSocket::install($runner, $app, WsRouteGroup::of([...]));
     * ```
     */
    public static function install(HttpRunner $runner, AppHost $app, WsRouteGroup $routes): void
    {
        $runner->upgrades()->register(
            self::UPGRADE_TOKEN,
            new WsServerUpgrade($app, $routes, $routes->gateway()),
        );
    }
}
