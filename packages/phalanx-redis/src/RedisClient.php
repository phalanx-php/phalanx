<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Clue\React\Redis\Client;
use React\Promise\PromiseInterface;

use function React\Async\await;

/**
 * Typed wrapper around clue/redis-react's magic-method Client.
 *
 * The inner Client uses __call() for all Redis commands. This wrapper
 * provides typed methods for common operations and falls through to
 * __call() for anything else.
 */
final class RedisClient
{
    public function __construct(private(set) Client $inner) {}

    public function get(string $key): mixed
    {
        return await($this->inner->__call('get', [$key]));
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        if ($ttl !== null) {
            await($this->inner->__call('setex', [$key, (string) $ttl, $value]));
        } else {
            await($this->inner->__call('set', [$key, $value]));
        }
    }

    public function del(string ...$keys): int
    {
        /** @var int */
        return await($this->inner->__call('del', $keys));
    }

    public function exists(string $key): bool
    {
        return (bool) await($this->inner->__call('exists', [$key]));
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) await($this->inner->__call('expire', [$key, (string) $seconds]));
    }

    public function incr(string $key): int
    {
        /** @var int */
        return await($this->inner->__call('incr', [$key]));
    }

    /** @return PromiseInterface<mixed> */
    public function raw(string $command, mixed ...$args): PromiseInterface
    {
        return $this->inner->__call($command, $args);
    }

    public function close(): void
    {
        $this->inner->end();
    }
}
