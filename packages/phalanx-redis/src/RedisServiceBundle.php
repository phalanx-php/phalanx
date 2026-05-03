<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Clue\React\Redis\Factory as RedisFactory;
use Phalanx\Scope\Suspendable;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class RedisServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $redisConfig = isset($context['redis_url'])
            ? RedisConfig::fromUrl($context['redis_url'])
            : new RedisConfig(
                host: $context['redis_host'] ?? '127.0.0.1',
                port: (int) ($context['redis_port'] ?? 6379),
                password: $context['redis_password'] ?? null,
                database: (int) ($context['redis_database'] ?? 0),
            );

        $services->singleton(RedisClient::class)
            ->factory(static function (Suspendable $scope) use ($redisConfig): RedisClient {
                $factory = new RedisFactory();
                $client = $factory->createLazyClient($redisConfig->toConnectionString());
                return new RedisClient($client, $scope);
            })
            ->onShutdown(static fn(RedisClient $client) => $client->close());

        $services->singleton(RedisPubSub::class)
            ->factory(static fn() => new RedisPubSub($redisConfig))
            ->onShutdown(static fn(RedisPubSub $pubsub) => $pubsub->close());
    }
}
