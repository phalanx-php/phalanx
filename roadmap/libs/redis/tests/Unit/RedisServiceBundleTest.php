<?php

declare(strict_types=1);

namespace Phalanx\Redis\Tests\Unit;

use Closure;
use Phalanx\Boot\AppContext;
use Phalanx\Redis\Redis;
use Phalanx\Redis\RedisClient;
use Phalanx\Redis\RedisConfig;
use Phalanx\Redis\RedisPool;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Redis\RedisServiceBundle;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\Services;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class RedisServiceBundleTest extends PhalanxTestCase
{
    #[Test]
    public function servicesRegisterConfiguredClientAndPubSub(): void
    {
        $result = $this->scope->run(
            static function (ExecutionScope $scope): array {
                $resolvedConfig = $scope->service(RedisConfig::class);

                self::assertInstanceOf(RedisPool::class, $scope->service(RedisPool::class));
                self::assertInstanceOf(RedisClient::class, Redis::client($scope));
                self::assertInstanceOf(RedisPubSub::class, Redis::pubsub($scope));

                return [
                    'host' => $resolvedConfig->host,
                    'port' => $resolvedConfig->port,
                    'database' => $resolvedConfig->database,
                ];
            },
            'test.redis.service-bundle',
        );

        self::assertSame([
            'host' => 'redis.test',
            'port' => 6380,
            'database' => 2,
        ], $result);
    }

    #[Test]
    public function facadeCreatesServiceBundle(): void
    {
        self::assertInstanceOf(RedisServiceBundle::class, Redis::services());
    }

    protected function phalanxServices(): Closure
    {
        return static function (Services $services, AppContext $context): void {
            new RedisServiceBundle(new RedisConfig(
                host: 'redis.test',
                port: 6380,
                database: 2,
            ))->services($services, $context);
        };
    }
}
