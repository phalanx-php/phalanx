<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Closure;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\WebSocket\Client\WsClient;
use Phalanx\WebSocket\Client\WsClientConfig;
use Phalanx\HttpClient\HttpClient;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Service\Services;
use Phalanx\SurrealDb\SurrealDb;
use Phalanx\SurrealDb\SurrealDbBundle;
use Phalanx\SurrealDb\SurrealDbConfig;
use Phalanx\SurrealDb\SurrealDbException;
use Phalanx\SurrealDb\SurrealDbLiveTransport;
use Phalanx\SurrealDb\SurrealDbTransport;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealDbBundleTest extends PhalanxTestCase
{
    #[Test]
    public function servicesComposeHttpClientAndRegisterSurrealDb(): void
    {
        $result = $this->scope->run(
            static function (ExecutionScope $scope): array {
                self::assertInstanceOf(HttpClient::class, $scope->service(HttpClient::class));
                self::assertInstanceOf(WsClient::class, $scope->service(WsClient::class));
                self::assertInstanceOf(SurrealDbTransport::class, $scope->service(SurrealDbTransport::class));
                self::assertInstanceOf(SurrealDbLiveTransport::class, $scope->service(SurrealDbLiveTransport::class));
                self::assertInstanceOf(SurrealDb::class, $scope->service(SurrealDb::class));

                $config = $scope->service(SurrealDbConfig::class);
                $wsConfig = $scope->service(WsClientConfig::class);

                return [
                    'namespace' => $config->namespace,
                    'database' => $config->database,
                    'endpoint' => $config->endpoint,
                    'websocketEndpoint' => $config->websocketEndpoint,
                    'wsConnectTimeout' => $wsConfig->connectTimeout,
                ];
            },
            'test.surrealdb.service-bundle',
        );

        self::assertSame([
            'namespace' => 'olympus',
            'database' => 'pantheon',
            'endpoint' => 'http://surrealdb.test:8000',
            'websocketEndpoint' => 'ws://surrealdb.test:8000/rpc',
            'wsConnectTimeout' => 5.0,
        ], $result);
    }

    #[Test]
    public function constructorConfigIsCanonicalForRegisteredSurrealDbConfig(): void
    {
        $result = Application::starting([
            'SURREAL_NAMESPACE' => 'context',
            'SURREAL_DATABASE' => 'context',
        ])
            ->providers(new SurrealDbBundle(new SurrealDbConfig(
                namespace: 'olympus',
                database: 'pantheon',
                endpoint: 'http://surrealdb.test:9000',
                connectTimeout: 1.5,
                readTimeout: 7.5,
                maxResponseBytes: 4096,
            )))
            ->run(Task::named(
                'test.surrealdb.bundle-explicit-config',
                static function (ExecutionScope $scope): array {
                    $config = $scope->service(SurrealDbConfig::class);

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
            'namespace' => 'olympus',
            'database' => 'pantheon',
            'endpoint' => 'http://surrealdb.test:9000',
            'connectTimeout' => 1.5,
            'readTimeout' => 7.5,
            'maxResponseBytes' => 4096,
        ], $result);
    }

    #[Test]
    public function surrealdbLocalQueryVariablesAreScopedToOneExecutionScope(): void
    {
        $query = 'SELECT * FROM oracle WHERE name = $topic';
        $firstParams = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.scoped-service.first',
                static function (ExecutionScope $scope) use ($query): array {
                    $surrealdb = $scope->service(SurrealDb::class);

                    $surrealdb->let('topic', 'Apollo');
                    $surrealdb->query($query);

                    $transport = $scope->service(SurrealDbTransport::class);
                    self::assertInstanceOf(BundleSurrealDbTransport::class, $transport);

                    return $transport->lastParams();
                },
            ),
        );
        $secondParams = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.scoped-service.second',
                static function (ExecutionScope $scope) use ($query): array {
                    $scope->service(SurrealDb::class)->query($query);

                    $transport = $scope->service(SurrealDbTransport::class);
                    self::assertInstanceOf(BundleSurrealDbTransport::class, $transport);

                    return $transport->lastParams();
                },
            ),
        );

        self::assertSame([$query, ['topic' => 'Apollo']], $firstParams);
        self::assertSame([$query], $secondParams);
    }

    #[Test]
    public function sharedInfrastructureIsNotScoped(): void
    {
        $first = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.shared-infrastructure.first',
                static fn(ExecutionScope $scope): array => [
                    spl_object_id($scope->service(HttpClient::class)),
                    spl_object_id($scope->service(SurrealDbTransport::class)),
                ],
            ),
        );
        $second = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.shared-infrastructure.second',
                static fn(ExecutionScope $scope): array => [
                    spl_object_id($scope->service(HttpClient::class)),
                    spl_object_id($scope->service(SurrealDbTransport::class)),
                ],
            ),
        );

        self::assertSame($first, $second);
    }

    #[Test]
    public function escapedSurrealDbFailsAfterOwningScopeDisposes(): void
    {
        $surrealdb = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.escaped-service',
                static fn(ExecutionScope $scope): SurrealDb => $scope->service(SurrealDb::class),
            ),
        );

        $this->expectException(SurrealDbException::class);
        $this->expectExceptionMessage('SurrealDb service was used after its owning scope was disposed.');

        $surrealdb->select('oracle:apollo');
    }

    #[Test]
    public function escapedDatabaseVariantFailsAfterOwningScopeDisposes(): void
    {
        $surrealdb = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.escaped-variant',
                static fn(ExecutionScope $scope): SurrealDb => $scope
                    ->service(SurrealDb::class)
                    ->withDatabase('athens', 'academy'),
            ),
        );

        $this->expectException(SurrealDbException::class);
        $this->expectExceptionMessage('SurrealDb service was used after its owning scope was disposed.');

        $surrealdb->select('oracle:apollo');
    }

    /** @return array<string, mixed> */
    #[\Override]
    protected function phalanxContext(): array
    {
        return [
            'SURREAL_NAMESPACE' => 'olympus',
            'SURREAL_DATABASE' => 'pantheon',
            'SURREAL_ENDPOINT' => 'http://surrealdb.test:8000',
        ];
    }

    protected function phalanxServices(): Closure
    {
        return static function (Services $services, AppContext $context): void {
            new SurrealDbBundle(transport: new BundleSurrealDbTransport())
                ->services($services, $context);
        };
    }
}

final class BundleSurrealDbTransport implements SurrealDbTransport
{
    /** @var list<array{method: string, params: list<mixed>}> */
    public array $calls = [];

    public function rpc(
        Scope&Suspendable $scope,
        SurrealDbConfig $config,
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

    public function status(Scope&Suspendable $scope, SurrealDbConfig $config, ?string $token): int
    {
        return 200;
    }

    public function health(Scope&Suspendable $scope, SurrealDbConfig $config, ?string $token): int
    {
        return 200;
    }
}
