<?php

declare(strict_types=1);

namespace Phalanx\Hermes;

use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;

class Hermes
{
    private function __construct()
    {
    }

    /** @param list<string> $subprotocols */
    public static function services(array $subprotocols = [], ?WsClientConfig $clientConfig = null): ServiceBundle
    {
        return new WsServiceBundle($subprotocols, $clientConfig);
    }

    public static function gateway(Scope $scope): WsGateway
    {
        return $scope->service(WsGateway::class);
    }
}
