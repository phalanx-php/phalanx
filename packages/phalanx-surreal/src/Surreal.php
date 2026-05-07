<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;

class Surreal
{
    private function __construct()
    {
    }

    public static function services(?SurrealConfig $config = null): ServiceBundle
    {
        return new SurrealServiceBundle($config);
    }

    public static function client(Scope $scope): SurrealClient
    {
        return $scope->service(SurrealClient::class);
    }
}
