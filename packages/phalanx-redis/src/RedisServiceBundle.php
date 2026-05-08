<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Optional;
use Phalanx\Scope\Suspendable;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;
use Phalanx\Trace\Trace;

class RedisServiceBundle extends ServiceBundle
{
    /**
     * Redis is optional infrastructure — the bundle boots without any Redis
     * env and falls back to 127.0.0.1:6379. Unavailability at boot is not
     * a hard failure; runtime feature flags handle degraded behaviour.
     * No TCP probe here: connection attempts happen at first pool checkout,
     * not at boot.
     */
    public static function harness(): BootHarness
    {
        return BootHarness::of(
            Optional::env('REDIS_URL', fallback: 'redis://127.0.0.1:6379', description: 'Redis connection URL'),
        );
    }


    public function __construct(
        private ?RedisConfig $config = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
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
