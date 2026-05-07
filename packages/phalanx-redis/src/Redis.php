<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Phalanx\Scope\Scope;
use Phalanx\Service\ServiceBundle;

class Redis
{
    private function __construct()
    {
    }

    public static function services(?RedisConfig $config = null): ServiceBundle
    {
        return new RedisServiceBundle($config);
    }

    public static function client(Scope $scope): RedisClient
    {
        return $scope->service(RedisClient::class);
    }

    public static function pubsub(Scope $scope): RedisPubSub
    {
        return $scope->service(RedisPubSub::class);
    }
}
