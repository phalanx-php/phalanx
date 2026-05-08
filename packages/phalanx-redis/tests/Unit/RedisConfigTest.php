<?php

declare(strict_types=1);

namespace Phalanx\Redis\Tests\Unit;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;
use Phalanx\Redis\RedisConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedisConfigTest extends TestCase
{
    #[Test]
    public function urlParsingPreservesConnectionTargetAndDatabase(): void
    {
        $config = RedisConfig::fromUrl('redis://athena:secret@redis.test:6380/3');

        self::assertSame('redis.test', $config->host);
        self::assertSame(6380, $config->port);
        self::assertSame('athena', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame(3, $config->database);
    }

    #[Test]
    public function defaultsAreNativePoolSafe(): void
    {
        $config = new RedisConfig();

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(6379, $config->port);
        self::assertNull($config->username);
        self::assertNull($config->password);
        self::assertSame(0, $config->database);
        self::assertSame(5.0, $config->connectTimeout);
        self::assertSame(30.0, $config->readTimeout);
        self::assertSame(16, $config->poolSize);
    }

    #[Test]
    public function contextAcceptsEnvironmentStyleKeys(): void
    {
        $config = RedisConfig::fromContext(AppContext::test([
            'REDIS_HOST' => 'redis.test',
            'REDIS_PORT' => '6380',
            'REDIS_USERNAME' => 'athena',
            'REDIS_PASSWORD' => 'secret',
            'REDIS_DATABASE' => '4',
            'REDIS_POOL_SIZE' => '8',
        ]));

        self::assertSame('redis.test', $config->host);
        self::assertSame(6380, $config->port);
        self::assertSame('athena', $config->username);
        self::assertSame('secret', $config->password);
        self::assertSame(4, $config->database);
        self::assertSame(8, $config->poolSize);
    }

    #[Test]
    public function invalidUrlFailsBeforeConnectionAttempt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis URL must use redis:// with a host.');

        RedisConfig::fromUrl('http://redis.test');
    }
}
