<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\AppHost;
use Phalanx\Http\Runner;
use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;
use Phalanx\WebSocket\Client\Config as ClientConfig;
use Phalanx\WebSocket\Server\Upgrade;

final class WebSocket
{
    public const string UPGRADE_TOKEN = 'websocket';

    private function __construct()
    {
    }

    public static function services(?ClientConfig $clientConfig = null): ServiceBundle
    {
        return new Bundle($clientConfig);
    }

    public static function client(Scope $scope): Client
    {
        return $scope->service(Client::class);
    }

    public static function gateway(Scope $scope): Gateway
    {
        return $scope->service(Gateway::class);
    }

    /**
     * Wire WebSocket's upgradeable into a HTTP runner.
     *
     * Call after the app is compiled and routes are attached:
     *
     * ```php
     * $app = Application::starting()->providers(WebSocket::services())->compile()->startup();
     * $runner = Runner::from($app)->withRoutes($routes);
     * WebSocket::install($runner, $app, RouteGroup::of([...]));
     * ```
     */
    public static function install(Runner $runner, AppHost $app, RouteGroup $routes): void
    {
        $runner->upgrades()->register(
            self::UPGRADE_TOKEN,
            new Upgrade($app, $routes, $routes->gateway()),
        );
    }
}
