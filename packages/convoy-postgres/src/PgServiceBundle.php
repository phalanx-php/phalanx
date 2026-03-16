<?php

declare(strict_types=1);

namespace Convoy\Postgres;

use Convoy\Service\ServiceBundle;
use Convoy\Service\Services;

final class PgServiceBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $pgConfig = isset($context['database_url'])
            ? PgConfig::fromDsn($context['database_url'])
            : new PgConfig(
                host: $context['pg_host'] ?? 'localhost',
                port: (int) ($context['pg_port'] ?? 5432),
                user: $context['pg_user'] ?? null,
                password: $context['pg_password'] ?? null,
                database: $context['pg_database'] ?? null,
                maxConnections: (int) ($context['pg_max_connections'] ?? 10),
                idleTimeout: (int) ($context['pg_idle_timeout'] ?? 60),
            );

        $services->singleton(PgPool::class)
            ->factory(static fn() => new PgPool($pgConfig))
            ->onShutdown(static fn(PgPool $pool) => $pool->close());

        $services->singleton(PgListener::class)
            ->needs(PgPool::class)
            ->factory(static fn(PgPool $pool) => new PgListener($pool))
            ->onShutdown(static fn(PgListener $listener) => $listener->unlistenAll());
    }
}
