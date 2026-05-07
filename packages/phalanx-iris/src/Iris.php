<?php

declare(strict_types=1);

namespace Phalanx\Iris;

use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;

class Iris
{
    private function __construct()
    {
    }

    public static function services(?HttpClientConfig $config = null): ServiceBundle
    {
        return new HttpServiceBundle($config);
    }

    public static function client(Scope $scope): HttpClient
    {
        return $scope->service(HttpClient::class);
    }
}
