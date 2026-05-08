<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Closure;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Hermes\Client\WsClient;
use Phalanx\Hermes\Client\WsClientConfig;
use Phalanx\Iris\HttpClient;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Service\Services;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealBundle;
use Phalanx\Surreal\SurrealConfig;
use Phalanx\Surreal\SurrealException;
use Phalanx\Surreal\SurrealLiveTransport;
use Phalanx\Surreal\SurrealTransport;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealBundleTest extends PhalanxTestCase
{
    #[Test]
    public function servicesComposeIrisAndRegisterSurreal(): void
    {
        $result = $this->scope->run(
            static function (ExecutionScope $scope): array {
                self::assertInstanceOf(HttpClient::class, $scope->service(HttpClient::class));
                self::assertInstanceOf(WsClient::class, $scope->service(WsClient::class));
                self::assertInstanceOf(SurrealTransport::class, $scope->service(SurrealTransport::class));
                self::assertInstanceOf(SurrealLiveTransport::class, $scope->service(SurrealLiveTransport::class));
                self::assertInstanceOf(Surreal::class, $scope->service(Surreal::class));

                $config = $scope->service(SurrealConfig::class);
                $wsConfig = $scope->service(WsClientConfig::class);

                return [
                    'namespace' => $config->namespace,
                    'database' => $config->database,
                    'endpoint' => $config->endpoint,
                    'websocketEndpoint' => $config->websocketEndpoint,
                    'wsConnectTimeout' => $wsConfig->connectTimeout,
                ];
            },
            'test.surreal.service-bundle',
        );

        self::assertSame([
            'namespace' => 'athena',
            'database' => 'wisdom',
            'endpoint' => 'http://surreal.test:8000',
            'websocketEndpoint' => 'ws://surreal.test:8000/rpc',
            'wsConnectTimeout' => 5.0,
        ], $result);
    }

    #[Test]
    public function constructorConfigIsCanonicalForRegisteredSurrealConfig(): void
    {
        $result = Application::starting([
            'surreal_namespace' => 'context',
            'surreal_database' => 'context',
        ])
            ->providers(new SurrealBundle(new SurrealConfig(
                namespace: 'athena',
                database: 'wisdom',
                endpoint: 'http://surreal.test:9000',
                connectTimeout: 1.5,
                readTimeout: 7.5,
                maxResponseBytes: 4096,
            )))
            ->run(Task::named(
                'test.surreal.bundle-explicit-config',
                static function (ExecutionScope $scope): array {
                    $config = $scope->service(SurrealConfig::class);

                    return [
                        'namespace' => $config->namespace,
                        'database' => $config->database,
                        'endpoint' => $config->endpoint,
                        'connectTimeout' => $config->connectTimeout,
                        'readTimeout' => $config->readTimeout,
                        'maxResponseBytes' => $config->maxResponseBytes,
                    ];
                },
            ));

        self::assertSame([
            'namespace' => 'athena',
            'database' => 'wisdom',
            'endpoint' => 'http://surreal.test:9000',
            'connectTimeout' => 1.5,
            'readTimeout' => 7.5,
            'maxResponseBytes' => 4096,
        ], $result);
    }

    #[Test]
    public function surrealLocalQueryVariablesAreScopedToOneExecutionScope(): void
    {
        $query = 'SELECT * FROM goddess WHERE name = $topic';
        $firstParams = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.scoped-service.first',
                static function (ExecutionScope $scope) use ($query): array {
                    $surreal = $scope->service(Surreal::class);

                    $surreal->let('topic', 'Athena');
                    $surreal->query($query);

                    $transport = $scope->service(SurrealTransport::class);
                    self::assertInstanceOf(BundleSurrealTransport::class, $transport);

                    return $transport->lastParams();
                },
            ),
        );
        $secondParams = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.scoped-service.second',
                static function (ExecutionScope $scope) use ($query): array {
                    $scope->service(Surreal::class)->query($query);

                    $transport = $scope->service(SurrealTransport::class);
                    self::assertInstanceOf(BundleSurrealTransport::class, $transport);

                    return $transport->lastParams();
                },
            ),
        );

        self::assertSame([$query, ['topic' => 'Athena']], $firstParams);
        self::assertSame([$query], $secondParams);
    }

    #[Test]
    public function sharedInfrastructureIsNotScoped(): void
    {
        $first = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.shared-infrastructure.first',
                static fn(ExecutionScope $scope): array => [
                    spl_object_id($scope->service(HttpClient::class)),
                    spl_object_id($scope->service(SurrealTransport::class)),
                ],
            ),
        );
        $second = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.shared-infrastructure.second',
                static fn(ExecutionScope $scope): array => [
                    spl_object_id($scope->service(HttpClient::class)),
                    spl_object_id($scope->service(SurrealTransport::class)),
                ],
            ),
        );

        self::assertSame($first, $second);
    }

    #[Test]
    public function escapedSurrealFailsAfterOwningScopeDisposes(): void
    {
        $surreal = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.escaped-service',
                static fn(ExecutionScope $scope): Surreal => $scope->service(Surreal::class),
            ),
        );

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal service was used after its owning scope was disposed.');

        $surreal->select('goddess:athena');
    }

    #[Test]
    public function escapedDatabaseVariantFailsAfterOwningScopeDisposes(): void
    {
        $surreal = $this->phalanx->app->scoped(
            Task::named(
                'test.surreal.escaped-variant',
                static fn(ExecutionScope $scope): Surreal => $scope
                    ->service(Surreal::class)
                    ->withDatabase('athens', 'academy'),
            ),
        );

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal service was used after its owning scope was disposed.');

        $surreal->select('goddess:athena');
    }

    /** @return array<string, mixed> */
    protected function phalanxContext(): array
    {
        return [
            'surreal_namespace' => 'athena',
            'surreal_database' => 'wisdom',
            'surreal_endpoint' => 'http://surreal.test:8000',
        ];
    }

    protected function phalanxServices(): Closure
    {
        return static function (Services $services, AppContext $context): void {
            new SurrealBundle(transport: new BundleSurrealTransport())
                ->services($services, $context);
        };
    }
}

final class BundleSurrealTransport implements SurrealTransport
{
    /** @var list<array{method: string, params: list<mixed>}> */
    public array $calls = [];

    public function rpc(
        Scope&Suspendable $scope,
        SurrealConfig $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed {
        $this->calls[] = [
            'method' => $method,
            'params' => $params,
        ];

        return ['ok' => true];
    }

    /** @return list<mixed> */
    public function lastParams(): array
    {
        $last = $this->calls[array_key_last($this->calls)] ?? null;

        return $last['params'] ?? [];
    }

    public function status(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int
    {
        return 200;
    }

    public function health(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int
    {
        return 200;
    }
}
