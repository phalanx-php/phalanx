<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Phalanx\Pool\ManagedPoolFactory;

/**
 * @implements ManagedPoolFactory<\Redis>
 */
final class RedisClientFactory implements ManagedPoolFactory
{
    public static function make(mixed $config): object
    {
        if (!$config instanceof RedisConfig) {
            throw new \InvalidArgumentException('RedisClientFactory expects a RedisConfig instance.');
        }

        return self::connect($config);
    }

    public static function connect(RedisConfig $config): \Redis
    {
        $redis = new \Redis();
        if (!$redis->connect($config->host, $config->port, $config->connectTimeout)) {
            throw new \RuntimeException('Redis connection failed.');
        }

        $redis->setOption(\Redis::OPT_READ_TIMEOUT, $config->readTimeout);

        $credentials = $config->username === null
            ? $config->password
            : [$config->username, $config->password ?? ''];

        if ($credentials !== null && !$redis->auth($credentials)) {
            throw new \RuntimeException('Redis authentication failed.');
        }

        if ($config->database !== 0 && !$redis->select($config->database)) {
            throw new \RuntimeException('Redis database selection failed.');
        }

        return $redis;
    }
}
