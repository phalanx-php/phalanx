<?php

declare(strict_types=1);

namespace Phalanx\Redis\Tests\Unit;

use Phalanx\Pool\ManagedPoolFactory;
use Phalanx\Redis\RedisClient;
use Phalanx\Redis\RedisConfig;
use Phalanx\Redis\RedisPool;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\Trace\Trace;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

final class RedisClientTest extends PhalanxTestCase
{
    #[Test]
    public function commandsRunThroughManagedPool(): void
    {
        FakeRedisFactory::$client = new FakeRedis();
        $pool = new RedisPool(
            new RedisConfig(host: 'redis.test', database: 2),
            new Trace(),
            FakeRedisFactory::class,
        );

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($pool): array {
                try {
                    $client = new RedisClient($pool, $scope);
                    $client->set('athena', 'wisdom', ttl: 30);

                    return [
                        'get' => $client->get('athena'),
                        'exists' => $client->exists('athena'),
                        'ttl' => $client->ttl('athena'),
                        'hgetall' => $client->hGetAll('goddess'),
                        'raw' => $client->raw('get', 'athena'),
                    ];
                } finally {
                    $pool->close();
                }
            },
            'test.redis.client.commands',
        );

        self::assertSame([
            'get' => 'wisdom',
            'exists' => 1,
            'ttl' => 30,
            'hgetall' => ['domain' => 'strategy'],
            'raw' => 'wisdom',
        ], $result);
        self::assertSame([
            ['setex', 'athena', 30, 'wisdom'],
            ['get', 'athena'],
            ['exists', ['athena']],
            ['ttl', 'athena'],
            ['hgetall', 'goddess'],
            ['get', 'athena'],
        ], FakeRedisFactory::$client->calls);
    }

    #[Test]
    public function managedPoolReleasesClientWhenRedisCommandThrows(): void
    {
        FakeRedisFactory::$client = new FakeRedis(failuresRemaining: 1);
        $pool = new RedisPool(
            new RedisConfig(),
            new Trace(),
            FakeRedisFactory::class,
        );

        $this->scope->run(
            static function (ExecutionScope $scope) use ($pool): void {
                try {
                    $client = new RedisClient($pool, $scope);

                    try {
                        $client->get('athena');
                        self::fail('Expected Redis get to throw.');
                    } catch (RuntimeException $e) {
                        self::assertSame('redis unavailable', $e->getMessage());
                    }

                    self::assertSame('wisdom', $client->get('athena'));
                } finally {
                    $pool->close();
                }
            },
            'test.redis.client.command-error-release',
        );

        self::assertSame([
            ['get', 'athena'],
            ['get', 'athena'],
        ], FakeRedisFactory::$client->calls);
    }

    #[Test]
    public function poolReportsConfiguredSize(): void
    {
        FakeRedisFactory::$client = new FakeRedis();
        $pool = new RedisPool(
            new RedisConfig(poolSize: 3),
            new Trace(),
            FakeRedisFactory::class,
        );

        self::assertSame(3, $pool->size);

        $this->scope->run(
            static function () use ($pool): void {
                $pool->close();
            },
            'test.redis.pool.close',
        );
    }
}

/**
 * @implements ManagedPoolFactory<FakeRedis>
 */
final class FakeRedisFactory implements ManagedPoolFactory
{
    public static FakeRedis $client;

    public static function make(mixed $config): object
    {
        return self::$client;
    }
}

final class FakeRedis extends \Redis
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public function __construct(
        private int $failuresRemaining = 0,
    ) {
    }

    public function get(string $key): mixed
    {
        $this->calls[] = ['get', $key];
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new RuntimeException('redis unavailable');
        }

        return 'wisdom';
    }

    public function setex(string $key, int $expire, mixed $value)
    {
        $this->calls[] = ['setex', $key, $expire, $value];
        return true;
    }

    public function exists(mixed $key, mixed ...$otherKeys): int
    {
        $this->calls[] = ['exists', [$key, ...$otherKeys]];
        return 1;
    }

    public function ttl(string $key): int
    {
        $this->calls[] = ['ttl', $key];
        return 30;
    }

    public function hGetAll(string $key): array
    {
        $this->calls[] = ['hgetall', $key];
        return ['domain' => 'strategy'];
    }
}
