<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Clue\React\Redis\Factory as RedisFactory;
use Phalanx\Scope\Suspendable;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class RedisServiceBundle implements ServiceBundle
{
    public function __construct(
        private readonly ?RedisConfig $config = null,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $redisConfig = $this->config ?? (isset($context['redis_url'])
            ? RedisConfig::fromUrl($context['redis_url'])
            : new RedisConfig(
                host: $context['redis_host'] ?? '127.0.0.1',
                port: (int) ($context['redis_port'] ?? 6379),
                password: $context['redis_password'] ?? null,
                database: (int) ($context['redis_database'] ?? 0),
            ));

        $services->config(RedisConfig::class, static fn(): RedisConfig => $redisConfig);

        $services->scoped(RedisClient::class)
            ->factory(static function (RedisConfig $config, Suspendable $scope): RedisClient {
                $factory = new RedisFactory();
                $client = $factory->createLazyClient($config->toConnectionString());
                return new RedisClient($client, $scope);
            })
            ->onDispose(static fn(RedisClient $client) => $client->close());

        $services->singleton(RedisPubSub::class)
            ->factory(static fn(RedisConfig $config) => new RedisPubSub($config))
            ->onShutdown(static fn(RedisPubSub $pubsub) => $pubsub->close());
    }
}
