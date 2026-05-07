<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Closure;
use Phalanx\Iris\HttpClient;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Service\Services;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealClient;
use Phalanx\Surreal\SurrealConfig;
use Phalanx\Surreal\SurrealTransport;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealServiceBundleTest extends PhalanxTestCase
{
    #[Test]
    public function servicesComposeIrisAndRegisterSurrealClient(): void
    {
        $result = $this->scope->run(
            static function (ExecutionScope $scope): array {
                self::assertInstanceOf(HttpClient::class, $scope->service(HttpClient::class));
                self::assertInstanceOf(SurrealTransport::class, $scope->service(SurrealTransport::class));
                self::assertInstanceOf(SurrealClient::class, Surreal::client($scope));

                $config = $scope->service(SurrealConfig::class);

                return [
                    'namespace' => $config->namespace,
                    'database' => $config->database,
                    'endpoint' => $config->endpoint,
                ];
            },
            'test.surreal.service-bundle',
        );

        self::assertSame([
            'namespace' => 'athena',
            'database' => 'wisdom',
            'endpoint' => 'http://surreal.test:8000',
        ], $result);
    }

    #[Test]
    public function surrealClientStateIsScopedToOneExecutionScope(): void
    {
        $first = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.scoped-client.first',
                static function (ExecutionScope $scope): int {
                    Surreal::client($scope)->let('topic', 'Athena');

                    return spl_object_id(Surreal::client($scope));
                },
            ),
        );
        $second = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.scoped-client.second',
                static fn(ExecutionScope $scope): int => spl_object_id(Surreal::client($scope)),
            ),
        );

        self::assertNotSame($first, $second);
    }

    protected function phalanxServices(): Closure
    {
        return static function (Services $services, array $context): void {
            Surreal::services(new SurrealConfig(
                namespace: 'athena',
                database: 'wisdom',
                endpoint: 'http://surreal.test:8000',
            ))->services($services, $context);
        };
    }
}
