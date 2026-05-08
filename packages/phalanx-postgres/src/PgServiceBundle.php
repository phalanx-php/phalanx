<?php

declare(strict_types=1);

namespace Phalanx\Postgres;

use Phalanx\Boot\AppContext;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class PgServiceBundle extends ServiceBundle
{
    public function __construct(
        private readonly ?PgConfig $config = null,
    ) {
    }

    public function services(Services $services, AppContext $context): void
    {
        $pgConfig = $this->config ?? ($context->has('database_url')
            ? PgConfig::fromDsn($context->string('database_url'))
            : new PgConfig(
                host: $context->string('pg_host', 'localhost'),
                port: $context->int('pg_port', 5432),
                user: $context->get('pg_user'),
                password: $context->get('pg_password'),
                database: $context->get('pg_database'),
                maxConnections: $context->int('pg_max_connections', 10),
                idleTimeout: $context->int('pg_idle_timeout', 60),
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
