<?php

declare(strict_types=1);

namespace Phalanx\Redis\Tests\Unit;

use Phalanx\Application;
use Phalanx\Redis\RedisClient;
use Phalanx\Redis\RedisConfig;
use Phalanx\Redis\RedisPubSub;
use Phalanx\Redis\RedisServiceBundle;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisServiceBundleTest extends TestCase
{
    #[Test]
    public function servicesRegisterConfiguredClientAndPubSub(): void
    {
        $config = new RedisConfig(host: 'redis.test', port: 6380, database: 2);

        $result = Application::starting()
            ->providers(new RedisServiceBundle($config))
            ->run(Task::named(
                'test.redis.service-bundle',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(RedisConfig::class);

                    self::assertInstanceOf(RedisClient::class, $scope->service(RedisClient::class));
                    self::assertInstanceOf(RedisPubSub::class, $scope->service(RedisPubSub::class));

                    return [
                        'host' => $resolvedConfig->host,
                        'port' => $resolvedConfig->port,
                        'database' => $resolvedConfig->database,
                    ];
                },
            ));

        self::assertSame([
            'host' => 'redis.test',
            'port' => 6380,
            'database' => 2,
        ], $result);
    }
}
