<?php

declare(strict_types=1);

namespace Phalanx\Postgres\Tests\Unit;

use Phalanx\Application;
use Phalanx\Postgres\PgConfig;
use Phalanx\Postgres\PgListener;
use Phalanx\Postgres\PgPool;
use Phalanx\Postgres\PgServiceBundle;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PgServiceBundleTest extends TestCase
{
    #[Test]
    public function servicesRegisterConfiguredPoolAndListener(): void
    {
        $config = new PgConfig(host: 'postgres.test', port: 15432, database: 'phalanx_test');

        $result = Application::starting()
            ->providers(new PgServiceBundle($config))
            ->run(Task::named(
                'test.postgres.service-bundle',
                static function (ExecutionScope $scope): array {
                    $resolvedConfig = $scope->service(PgConfig::class);

                    self::assertInstanceOf(PgPool::class, $scope->service(PgPool::class));
                    self::assertInstanceOf(PgListener::class, $scope->service(PgListener::class));

                    return [
                        'host' => $resolvedConfig->host,
                        'port' => $resolvedConfig->port,
                        'database' => $resolvedConfig->database,
                    ];
                },
            ));

        self::assertSame([
            'host' => 'postgres.test',
            'port' => 15432,
            'database' => 'phalanx_test',
        ], $result);
    }
}
