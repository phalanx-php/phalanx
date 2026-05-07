<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Closure;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;

final class RedisClient
{
    public function __construct(
        private readonly RedisPool $pool,
        private readonly Suspendable $scope,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->command('get', static fn(\Redis $redis): mixed => $redis->get($key));
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        if ($ttl !== null) {
            $this->command('setex', static fn(\Redis $redis): mixed => $redis->setex($key, $ttl, $value));
        } else {
            $this->command('set', static fn(\Redis $redis): mixed => $redis->set($key, $value));
        }
    }

    public function del(string ...$keys): int
    {
        return (int) $this->command('del', static fn(\Redis $redis): mixed => $redis->del(...$keys));
    }

    public function exists(string ...$keys): int
    {
        return (int) $this->command('exists', static fn(\Redis $redis): mixed => $redis->exists(...$keys));
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->command('expire', static fn(\Redis $redis): mixed => $redis->expire($key, $seconds));
    }

    public function ttl(string $key): int
    {
        return (int) $this->command('ttl', static fn(\Redis $redis): mixed => $redis->ttl($key));
    }

    public function incr(string $key): int
    {
        return (int) $this->command('incr', static fn(\Redis $redis): mixed => $redis->incr($key));
    }

    public function decr(string $key): int
    {
        return (int) $this->command('decr', static fn(\Redis $redis): mixed => $redis->decr($key));
    }

    public function hGet(string $key, string $field): mixed
    {
        return $this->command('hget', static fn(\Redis $redis): mixed => $redis->hGet($key, $field));
    }

    public function hSet(string $key, string $field, mixed $value): int
    {
        return (int) $this->command('hset', static fn(\Redis $redis): mixed => $redis->hSet($key, $field, $value));
    }

    /** @return array<string, mixed> */
    public function hGetAll(string $key): array
    {
        $result = $this->command('hgetall', static fn(\Redis $redis): mixed => $redis->hGetAll($key));
        return is_array($result) ? $result : [];
    }

    public function lPush(string $key, mixed ...$values): int
    {
        return (int) $this->command('lpush', static fn(\Redis $redis): mixed => $redis->lPush($key, ...$values));
    }

    public function rPush(string $key, mixed ...$values): int
    {
        return (int) $this->command('rpush', static fn(\Redis $redis): mixed => $redis->rPush($key, ...$values));
    }

    public function lPop(string $key): mixed
    {
        return $this->command('lpop', static fn(\Redis $redis): mixed => $redis->lPop($key));
    }

    public function rPop(string $key): mixed
    {
        return $this->command('rpop', static fn(\Redis $redis): mixed => $redis->rPop($key));
    }

    public function publish(string $channel, string $message): int
    {
        return (int) $this->command('publish', static fn(\Redis $redis): mixed => $redis->publish($channel, $message));
    }

    public function raw(string $command, mixed ...$args): mixed
    {
        return $this->command($command, static fn(\Redis $redis): mixed => $redis->{$command}(...$args));
    }

    public function close(): void
    {
    }

    private function command(string $command, Closure $operation): mixed
    {
        $pool = $this->pool;
        $scope = $this->scope;

        return $this->scope->call(
            static fn(): mixed => $pool->use($scope, $operation),
            WaitReason::redis($command),
        );
    }
}
