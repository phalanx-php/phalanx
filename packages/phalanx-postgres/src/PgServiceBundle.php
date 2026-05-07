<?php

declare(strict_types=1);

namespace Phalanx\Postgres;

use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class PgServiceBundle implements ServiceBundle
{
    public function __construct(
        private readonly ?PgConfig $config = null,
    ) {
    }

    public function services(Services $services, array $context): void
    {
        $pgConfig = $this->config ?? (isset($context['database_url'])
            ? PgConfig::fromDsn($context['database_url'])
            : new PgConfig(
                host: $context['pg_host'] ?? 'localhost',
                port: (int) ($context['pg_port'] ?? 5432),
                user: $context['pg_user'] ?? null,
                password: $context['pg_password'] ?? null,
                database: $context['pg_database'] ?? null,
                maxConnections: (int) ($context['pg_max_connections'] ?? 10),
                idleTimeout: (int) ($context['pg_idle_timeout'] ?? 60),
            ));

        $services->config(PgConfig::class, static fn(): PgConfig => $pgConfig);

        $services->singleton(PgPool::class)
            ->factory(static fn(PgConfig $config) => new PgPool($config))
            ->onShutdown(static fn(PgPool $pool) => $pool->close());

        $services->singleton(PgListener::class)
            ->needs(PgPool::class)
            ->factory(static fn(PgPool $pool) => new PgListener($pool))
            ->onShutdown(static fn(PgListener $listener) => $listener->unlistenAll());
    }
}
