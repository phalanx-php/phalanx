<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Clue\React\Redis\Client;
use Phalanx\Scope\Suspendable;
use Phalanx\Supervisor\WaitReason;
use React\Promise\PromiseInterface;

use function React\Async\await;

final class RedisClient
{
    public function __construct(
        private readonly Client $inner,
        private readonly Suspendable $scope,
    ) {}

    public function get(string $key): mixed
    {
        return $this->awaitCommand('get', $key);
    }

    public function set(string $key, string $value, ?int $ttl = null): void
    {
        if ($ttl !== null) {
            $this->awaitCommand('setex', $key, (string) $ttl, $value);
        } else {
            $this->awaitCommand('set', $key, $value);
        }
    }

    public function del(string ...$keys): int
    {
        /** @var int */
        return $this->awaitCommand('del', ...$keys);
    }

    public function exists(string $key): bool
    {
        return (bool) $this->awaitCommand('exists', $key);
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->awaitCommand('expire', $key, (string) $seconds);
    }

    public function incr(string $key): int
    {
        /** @var int */
        return $this->awaitCommand('incr', $key);
    }

    public function decr(string $key): int
    {
        /** @var int */
        return $this->awaitCommand('decr', $key);
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

    private function awaitCommand(string $command, mixed ...$args): mixed
    {
        $client = $this->inner;

        return $this->scope->call(
            static fn(): mixed => await($client->__call($command, $args)),
            WaitReason::redis($command),
        );
    }
}
