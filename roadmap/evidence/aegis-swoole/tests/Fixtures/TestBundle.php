<?php

declare(strict_types=1);

namespace AegisSwoole\Tests\Fixtures;

use AegisSwoole\Http\HttpClient;
use AegisSwoole\Llm\LlmClient;
use AegisSwoole\Llm\LlmConfig;
use AegisSwoole\Postgres\PostgresPool;
use AegisSwoole\Postgres\PostgresPoolConfig;
use AegisSwoole\Scope\DeferredScope;
use AegisSwoole\Service\ServiceBundle;
use AegisSwoole\Service\Services;

class TestBundle implements ServiceBundle
{
    public function services(Services $services, array $context): void
    {
        $services->singleton(DeferredScope::class)
            ->factory(static fn(): DeferredScope => new DeferredScope());

        $services->scoped(HttpClient::class)
            ->needs(DeferredScope::class)
            ->factory(static fn(DeferredScope $scope): HttpClient => new HttpClient($scope));

        $services->singleton(PostgresPoolConfig::class)
            ->factory(static fn(): PostgresPoolConfig => new PostgresPoolConfig(
                host: '127.0.0.1',
                port: 5432,
                database: 'postgres',
                username: 'postgres',
                password: 'password',
                size: 5,
            ));

        $services->singleton(PostgresPool::class)
            ->needs(DeferredScope::class, PostgresPoolConfig::class)
            ->factory(static fn(DeferredScope $scope, PostgresPoolConfig $cfg): PostgresPool
                => new PostgresPool($scope, $cfg))
            ->onShutdown(static function (object $pool): void {
                /** @var PostgresPool $pool */
                $pool->close();
            });

        $services->singleton(LlmConfig::class)
            ->factory(static fn(): LlmConfig => new LlmConfig());

        $services->scoped(LlmClient::class)
            ->needs(DeferredScope::class, LlmConfig::class)
            ->factory(static fn(DeferredScope $scope, LlmConfig $cfg): LlmClient
                => new LlmClient($scope, $cfg));


        $services->singleton(CounterService::class)
            ->factory(static fn() => new CounterService());

        $services->scoped(DisposableService::class)
            ->factory(static fn() => new DisposableService())
            ->onDispose(static function (object $svc): void {
                /** @var DisposableService $svc */
                $svc->disposed = true;
            });

        $services->eager(EagerService::class)
            ->factory(static fn() => new EagerService())
            ->onInit(static function (object $svc): void {
                /** @var EagerService $svc */
                $svc->built = true;
            });

        $services->eager(LifecycleService::class)
            ->factory(static fn() => new LifecycleService())
            ->onInit(static function (object $svc): void {
                /** @var LifecycleService $svc */
                $svc->initCount++;
            })
            ->onStartup(static function (object $svc): void {
                /** @var LifecycleService $svc */
                $svc->startupCount++;
            })
            ->onShutdown(static function (object $svc): void {
                /** @var LifecycleService $svc */
                $svc->shutdownCount++;
            });

        $services->singleton(HelloGreeter::class)
            ->factory(static fn() => new HelloGreeter());
        $services->alias(Greeter::class, HelloGreeter::class);

        $services->config(AppNameConfig::class, static fn(array $ctx): AppNameConfig => new AppNameConfig(
            (string) ($ctx['app.name'] ?? 'aegis-swoole'),
        ));
    }
}
