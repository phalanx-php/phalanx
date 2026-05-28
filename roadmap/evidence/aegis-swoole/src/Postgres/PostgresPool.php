<?php

declare(strict_types=1);

namespace AegisSwoole\Postgres;

use AegisSwoole\Cancellation\Cancelled;
use AegisSwoole\Scope\Suspendable;
use OpenSwoole\Core\Coroutine\Client\PostgresClientFactory;
use OpenSwoole\Core\Coroutine\Client\PostgresConfig;
use OpenSwoole\Core\Coroutine\Pool\ClientPool;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\PostgreSQL;
use Throwable;

/**
 * Service-level wrapper over openswoole/core's `ClientPool` + `PostgresClientFactory`,
 * which uses the native `OpenSwoole\Coroutine\PostgreSQL` async client (requires
 * the extension to be built with `--with-postgres`).
 *
 * Each `query()` / `execute()` call:
 *   1. checks out a `PostgreSQL` from the pool (yielding the coroutine if pool empty)
 *   2. runs the operation inside `$scope->call(...)` so scope cancellation
 *      propagates as `Cancelled` (Coroutine::cancel interrupts in-flight queries)
 *   3. returns the client to the pool in `finally`
 *
 * Substrate finding (Phase 9, OpenSwoole 26.2 with --with-postgres): calling
 * `PostgreSQL::prepare()` then `$stmt->execute()` segfaults when the underlying
 * client is reused across coroutines via the pool (verified by reducing 10
 * concurrent queries-over-pool-of-5 to a SIGSEGV at exactly N>poolSize). The
 * raw `query()` path is safe under pool recycling. We therefore parameterise
 * by escaping literals client-side via `$client->escapeLiteral()` and
 * substituting positional placeholders (`$1, $2, ...`) into the SQL ourselves.
 *
 * `close()` empties the pool. The native `PostgreSQL` class has no `close()`
 * method; libpq teardown happens via the object's `__destruct`. We pop and
 * discard each connection rather than calling `$client->close()` (which would
 * fail with "Call to undefined method").
 */
class PostgresPool
{
    private readonly ClientPool $pool;

    public function __construct(
        private readonly Suspendable $scope,
        PostgresPoolConfig $config,
    ) {
        $pgConfig = (new PostgresConfig())
            ->withHost($config->host)
            ->withPort($config->port)
            ->withDbname($config->database)
            ->withUsername($config->username)
            ->withPassword($config->password);
        $this->pool = new ClientPool(PostgresClientFactory::class, $pgConfig, $config->size);
    }

    /**
     * @param list<int|float|string|bool|null> $params
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $pool = $this->pool;
        return $this->scope->call(static function () use ($pool, $sql, $params): array {
            /** @var PostgreSQL $client */
            $client = $pool->get();
            try {
                $finalSql = $params === [] ? $sql : self::interpolateParams($client, $sql, $params);
                $result = $client->query($finalSql);
                if ($result === false) {
                    if (Coroutine::isCanceled()) {
                        throw new Cancelled('postgres query cancelled');
                    }
                    throw new \RuntimeException('postgres query failed: ' . $client->error);
                }
                /** @var list<array<string, mixed>> $rows */
                $rows = $result->fetchAll(PGSQL_ASSOC) ?: [];
                return $rows;
            } finally {
                $pool->put($client);
            }
        });
    }

    /** @param list<int|float|string|bool|null> $params */
    public function execute(string $sql, array $params = []): int
    {
        $pool = $this->pool;
        return (int) $this->scope->call(static function () use ($pool, $sql, $params): int {
            /** @var PostgreSQL $client */
            $client = $pool->get();
            try {
                $finalSql = $params === [] ? $sql : self::interpolateParams($client, $sql, $params);
                $result = $client->query($finalSql);
                if ($result === false) {
                    if (Coroutine::isCanceled()) {
                        throw new Cancelled('postgres query cancelled');
                    }
                    throw new \RuntimeException('postgres query failed: ' . $client->error);
                }
                return (int) $result->affectedRows();
            } finally {
                $pool->put($client);
            }
        });
    }

    /**
     * Replaces `$1, $2, ...` placeholders with libpq-escaped literal values.
     * `escapeLiteral` returns the value already wrapped in single quotes
     * (e.g. `'foo'`), so callers must NOT add their own quotes.
     *
     * @param list<int|float|string|bool|null> $params
     */
    private static function interpolateParams(PostgreSQL $client, string $sql, array $params): string
    {
        return preg_replace_callback(
            '/\$(\d+)/',
            static function (array $m) use ($client, $params): string {
                $idx = (int) $m[1] - 1;
                if (!array_key_exists($idx, $params)) {
                    throw new \RuntimeException("postgres: missing parameter \${$m[1]}");
                }
                $value = $params[$idx];
                if ($value === null) {
                    return 'NULL';
                }
                if (is_bool($value)) {
                    return $value ? "'t'" : "'f'";
                }
                if (is_int($value) || is_float($value)) {
                    return (string) $value;
                }
                $escaped = $client->escapeLiteral((string) $value);
                if ($escaped === false) {
                    throw new \RuntimeException('postgres: escapeLiteral failed');
                }
                return $escaped;
            },
            $sql,
        ) ?? throw new \RuntimeException('postgres: parameter substitution failed');
    }

    public function close(): void
    {
        try {
            $reflection = new \ReflectionClass($this->pool);
            $poolProp = $reflection->getProperty('pool');
            $poolProp->setAccessible(true);
            $channel = $poolProp->getValue($this->pool);
            if ($channel === null) {
                return;
            }
            // Drain the channel without calling the missing PostgreSQL::close().
            while (!$channel->isEmpty()) {
                $channel->pop(0.001);
            }
            $channel->close();
            $poolProp->setValue($this->pool, null);
        } catch (Throwable) {
            // best-effort: GC will reclaim the connections via __destruct
        }
    }
}
