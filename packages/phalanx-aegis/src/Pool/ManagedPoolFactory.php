<?php

declare(strict_types=1);

namespace Phalanx\Pool;

/**
 * Factory contract for {@see ManagedPool}.
 *
 * Implementations produce a fresh client object when the pool needs to
 * grow. The contract intentionally matches the static-`make()` shape used
 * by `OpenSwoole\Core\Coroutine\Pool\ClientPool` so a Phalanx ManagedPool
 * can be built on top of the OpenSwoole core pool by passing the factory
 * class through directly. Callers compose a small named class implementing
 * this interface and `make()` (e.g. `PostgresClientFactory`,
 * `RedisClientFactory`).
 *
 * @template T of ManagedPoolClient
 */
interface ManagedPoolFactory
{
    /**
     * Construct a fresh client. Receives the pool's config payload exactly
     * as it was supplied at pool construction time.
     *
     * @param mixed $config opaque config object passed at pool construction
     * @return T
     */
    public static function make(mixed $config): ManagedPoolClient;
}
