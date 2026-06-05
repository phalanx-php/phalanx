<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Closure;
use Phalanx\Application;
use Phalanx\Boot\AppContext;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Service\Services;
use Phalanx\Task\Task;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class BundleTest extends PhalanxTestCase
{
    #[Test]
    public function servicesComposeHttpClientAndRegisterSurrealDb(): void
    {
        $result = $this->scope->run(
            static function (ExecutionScope $scope): array {
                self::assertInstanceOf(\Phalanx\HttpClient\Client::class, $scope->service(\Phalanx\HttpClient\Client::class));
                self::assertInstanceOf(\Phalanx\WebSocket\Client::class, $scope->service(\Phalanx\WebSocket\Client::class));
                self::assertInstanceOf(\Phalanx\SurrealDb\Transport::class, $scope->service(\Phalanx\SurrealDb\Transport::class));
                self::assertInstanceOf(\Phalanx\SurrealDb\Live\Transport::class, $scope->service(\Phalanx\SurrealDb\Live\Transport::class));
                self::assertInstanceOf(\Phalanx\SurrealDb\Client::class, $scope->service(\Phalanx\SurrealDb\Client::class));

                $config = $scope->service(\Phalanx\SurrealDb\Config::class);
                $wsConfig = $scope->service(\Phalanx\WebSocket\Client\Config::class);

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
    public function constructorConfigIsCanonicalForRegisteredConfig(): void
    {
        $result = Application::starting([
            'SURREAL_NAMESPACE' => 'context',
            'SURREAL_DATABASE' => 'context',
        ])
            ->providers(new \Phalanx\SurrealDb\Bundle(new \Phalanx\SurrealDb\Config(
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
                    $config = $scope->service(\Phalanx\SurrealDb\Config::class);

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
                    $surrealdb = $scope->service(\Phalanx\SurrealDb\Client::class);

                    $surrealdb->let('topic', 'Apollo');
                    $surrealdb->query($query);

                    $transport = $scope->service(\Phalanx\SurrealDb\Transport::class);
                    self::assertInstanceOf(\Phalanx\SurrealDb\Tests\Unit\BundleTransport::class, $transport);

                    return $transport->lastParams();
                },
            ),
        );
        $secondParams = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.scoped-service.second',
                static function (ExecutionScope $scope) use ($query): array {
                    $scope->service(\Phalanx\SurrealDb\Client::class)->query($query);

                    $transport = $scope->service(\Phalanx\SurrealDb\Transport::class);
                    self::assertInstanceOf(\Phalanx\SurrealDb\Tests\Unit\BundleTransport::class, $transport);

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
                    spl_object_id($scope->service(\Phalanx\HttpClient\Client::class)),
                    spl_object_id($scope->service(\Phalanx\SurrealDb\Transport::class)),
                ],
            ),
        );
        $second = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.shared-infrastructure.second',
                static fn(ExecutionScope $scope): array => [
                    spl_object_id($scope->service(\Phalanx\HttpClient\Client::class)),
                    spl_object_id($scope->service(\Phalanx\SurrealDb\Transport::class)),
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
                static fn(ExecutionScope $scope): \Phalanx\SurrealDb\Client => $scope->service(\Phalanx\SurrealDb\Client::class),
            ),
        );

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb service was used after its owning scope was disposed.');

        $surrealdb->select('oracle:apollo');
    }

    #[Test]
    public function escapedDatabaseVariantFailsAfterOwningScopeDisposes(): void
    {
        $surrealdb = $this->phalanx->app->scoped(
            Task::named(
                'test.surrealdb.escaped-variant',
                static fn(ExecutionScope $scope): \Phalanx\SurrealDb\Client => $scope
                    ->service(\Phalanx\SurrealDb\Client::class)
                    ->withDatabase('athens', 'academy'),
            ),
        );

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
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
            new \Phalanx\SurrealDb\Bundle(transport: new \Phalanx\SurrealDb\Tests\Unit\BundleTransport())
                ->services($services, $context);
        };
    }
}

final class BundleTransport implements \Phalanx\SurrealDb\Transport
{
    /** @var list<array{method: string, params: list<mixed>}> */
    public array $calls = [];

    public function rpc(
        Scope&Suspendable $scope,
        \Phalanx\SurrealDb\Config $config,
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

    public function status(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int
    {
        return 200;
    }

    public function health(Scope&Suspendable $scope, \Phalanx\SurrealDb\Config $config, ?string $token): int
    {
        return 200;
    }
}
