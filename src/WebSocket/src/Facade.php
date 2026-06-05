<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\AppHost;
use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;

final class Facade
{
    public const string UPGRADE_TOKEN = 'websocket';

    private function __construct()
    {
    }

    public static function services(?\Phalanx\WebSocket\Client\Config $clientConfig = null): ServiceBundle
    {
        return new \Phalanx\WebSocket\Bundle($clientConfig);
    }

    public static function client(Scope $scope): \Phalanx\WebSocket\Client
    {
        return $scope->service(\Phalanx\WebSocket\Client::class);
    }

    public static function gateway(Scope $scope): \Phalanx\WebSocket\Gateway
    {
        return $scope->service(\Phalanx\WebSocket\Gateway::class);
    }

    /**
     * Wire WebSocket's upgradeable into a HTTP runner.
     *
     * Call after the app is compiled and routes are attached:
     *
     * ```php
     * $app = Application::starting()->providers(WebSocket::services())->compile()->startup();
     * $runner = Runner::from($app)->withRoutes($routes);
     * Facade::install($runner, $app, RouteGroup::of([...]));
     * ```
     */
    public static function install(\Phalanx\Http\Runner $runner, AppHost $app, \Phalanx\WebSocket\RouteGroup $routes): void
    {
        $runner->upgrades()->register(
            self::UPGRADE_TOKEN,
            new \Phalanx\WebSocket\Server\Upgrade($app, $routes, $routes->gateway()),
        );
    }
}
