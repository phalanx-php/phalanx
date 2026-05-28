<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Closure;
use Phalanx\Pool\ManagedPool;
use Phalanx\Scope\Suspendable;
use Phalanx\Trace\Trace;
use RuntimeException;

final class RedisPool
{
    public int $size {
        get => $this->pool->size;
    }

    private readonly ManagedPool $pool;

    /** @param class-string $factoryClass */
    public function __construct(
        RedisConfig $config,
        Trace $trace,
        string $factoryClass = RedisClientFactory::class,
    ) {
        $this->pool = new ManagedPool(
            domain: 'redis/main',
            factoryClass: $factoryClass,
            config: $config,
            trace: $trace,
            size: $config->poolSize,
            heartbeat: false,
        );
    }

    /** @param Closure(\Redis): mixed $work */
    public function use(Suspendable $scope, Closure $work): mixed
    {
        return $this->pool->use($scope, static function (object $client) use ($work): mixed {
            if (!$client instanceof \Redis) {
                throw new RuntimeException('RedisPool expected a native Redis client.');
            }

            return $work($client);
        });
    }

    public function close(): void
    {
        $this->pool->close();
    }
}
