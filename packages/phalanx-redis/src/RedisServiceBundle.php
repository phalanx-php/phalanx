<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Phalanx\Scope\Suspendable;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Trace\Trace;

final class RedisServiceBundle extends ServiceBundle
{
    public function __construct(
        private readonly ?RedisConfig $config = null,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $redisConfig = $this->config ?? RedisConfig::fromContext($context);

        $services->config(RedisConfig::class, static fn(): RedisConfig => $redisConfig);

        $services->singleton(RedisPool::class)
            ->needs(RedisConfig::class, Trace::class)
            ->factory(static fn(RedisConfig $config, Trace $trace): RedisPool => new RedisPool($config, $trace))
            ->onShutdown(static function (RedisPool $pool): void {
                $pool->close();
            });

        $services->scoped(RedisClient::class)
            ->factory(static fn(RedisPool $pool, Suspendable $scope): RedisClient => new RedisClient($pool, $scope))
            ->onDispose(static fn(RedisClient $client) => $client->close());

        $services->singleton(RedisPubSub::class)
            ->factory(static fn(RedisConfig $config) => new RedisPubSub($config))
            ->onShutdown(static fn(RedisPubSub $pubsub) => $pubsub->close());
    }
}
