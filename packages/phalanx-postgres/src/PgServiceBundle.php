<?php

declare(strict_types=1);

namespace Phalanx\Postgres;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\BootHarness;
use Phalanx\Boot\Optional;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\Services;

final class PgServiceBundle extends ServiceBundle
{
    /**
     * The bundle supports two config shapes: a single DSN via `database_url`
     * or split host/port/database keys. `database_url` is the conventional
     * twelve-factor form. Split keys each have sensible defaults (host=localhost,
     * port=5432) so they are Optional; only `database_url` is Required when
     * the split keys are not provided.
     *
     * We declare both patterns as optional here because the bundle resolves
     * whichever is present at runtime — the actual required-ness depends on
     * which config path the operator chooses. If neither is set, PgConfig
     * construction will fail at boot time with a clear MissingContextValue.
     */
    public static function harness(): BootHarness
    {
        return BootHarness::of(
            Optional::env('database_url', description: 'PostgreSQL DSN (e.g. pgsql://user:pass@host/db)'),
            Optional::env('pg_host', fallback: 'localhost', description: 'PostgreSQL host (split-key config)'),
            Optional::env('pg_port', fallback: '5432', description: 'PostgreSQL port (split-key config)'),
            Optional::env('pg_database', description: 'PostgreSQL database name (split-key config)'),
        );
    }


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
