<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\SurrealClient;
use Phalanx\Surreal\SurrealConfig;
use Phalanx\Surreal\SurrealException;
use Phalanx\Surreal\SurrealTransport;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealClientTest extends PhalanxTestCase
{
    #[Test]
    public function queryUnwrapsStatementResultsAndMergesLetParameters(): void
    {
        $transport = new FakeSurrealTransport([
            [['status' => 'OK', 'result' => [['id' => 'goddess:athena']]]],
        ]);
        $client = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
            $transport,
        );
        $client->let('topic', 'Athena');
        $query = 'SELECT * FROM goddess WHERE name = $topic';

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $client->query($scope, $query),
            'test.surreal.client.query',
        );

        self::assertSame([[['id' => 'goddess:athena']]], $result);
        self::assertSame('query', $transport->calls[0]['method']);
        self::assertSame(
            [$query, ['topic' => 'Athena']],
            $transport->calls[0]['params'],
        );
    }

    #[Test]
    public function recordMethodsMapToSurrealRpcMethods(): void
    {
        $transport = new FakeSurrealTransport(array_fill(0, 11, ['ok' => true]));
        $client = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
            $transport,
        );

        $this->scope->run(
            static function (ExecutionScope $scope) use ($client): void {
                $client->select($scope, 'goddess:athena');
                $client->create($scope, 'goddess', ['name' => 'Athena']);
                $client->insert($scope, 'goddess', [['name' => 'Athena']]);
                $client->insertRelation($scope, 'guides', ['in' => 'goddess:athena', 'out' => 'city:athens']);
                $client->update($scope, 'goddess:athena', ['domain' => 'wisdom']);
                $client->upsert($scope, 'goddess:athena', ['domain' => 'strategy']);
                $client->merge($scope, 'goddess:athena', ['symbol' => 'owl']);
                $client->patch(
                    $scope,
                    'goddess:athena',
                    [['op' => 'replace', 'path' => '/domain', 'value' => 'strategy']],
                    diff: true,
                );
                $client->delete($scope, 'goddess:athena');
                $client->relate($scope, ['goddess:athena'], 'guides', ['city:athens'], ['lesson' => 'strategy']);
                $client->run($scope, 'fn::wisdom', '1.0.0', ['Athena']);
            },
            'test.surreal.client.records',
        );

        self::assertSame(
            [
                'select',
                'create',
                'insert',
                'insert_relation',
                'update',
                'upsert',
                'merge',
                'patch',
                'delete',
                'relate',
                'run',
            ],
            array_column($transport->calls, 'method'),
        );
        self::assertSame(['goddess:athena'], $transport->calls[0]['params']);
        self::assertSame(
            ['goddess:athena', [['op' => 'replace', 'path' => '/domain', 'value' => 'strategy']], true],
            $transport->calls[7]['params'],
        );
        self::assertSame(
            [['goddess:athena'], 'guides', ['city:athens'], ['lesson' => 'strategy']],
            $transport->calls[9]['params'],
        );
        self::assertSame(['fn::wisdom', '1.0.0', ['Athena']], $transport->calls[10]['params']);
        self::assertSame(array_fill(0, 11, 'jwt'), array_column($transport->calls, 'token'));
    }

    #[Test]
    public function withDatabaseDoesNotMutateBaseClient(): void
    {
        $transport = new FakeSurrealTransport([['ok' => true], ['ok' => true]]);
        $base = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
            $transport,
        );
        $alternate = $base->withDatabase('athens', 'library');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($base, $alternate): void {
                $alternate->select($scope, 'scroll');
                $base->select($scope, 'goddess');
            },
            'test.surreal.client.with-database',
        );

        self::assertSame('athens', $transport->calls[0]['namespace']);
        self::assertSame('library', $transport->calls[0]['database']);
        self::assertSame('olympus', $transport->calls[1]['namespace']);
        self::assertSame('pantheon', $transport->calls[1]['database']);
    }

    #[Test]
    public function signinCachesTokenWhenSurrealReturnsRecordToken(): void
    {
        $transport = new FakeSurrealTransport(['jwt-token', ['ok' => true]]);
        $client = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
            $transport,
        );

        $this->scope->run(
            static function (ExecutionScope $scope) use ($client): void {
                $token = $client->signin($scope, [
                    'NS' => 'olympus',
                    'DB' => 'pantheon',
                    'AC' => 'reader',
                    'user' => 'athena',
                    'pass' => 'shield',
                ]);

                self::assertSame('jwt-token', $token);
                $client->select($scope, 'goddess');
            },
            'test.surreal.client.record-signin',
        );

        self::assertSame(['signin', 'select'], array_column($transport->calls, 'method'));
        self::assertNull($transport->calls[0]['token']);
        self::assertSame('jwt-token', $transport->calls[1]['token']);
        self::assertSame(
            [[
                'NS' => 'olympus',
                'DB' => 'pantheon',
                'AC' => 'reader',
                'user' => 'athena',
                'pass' => 'shield',
            ]],
            $transport->calls[0]['params'],
        );
    }

    #[Test]
    public function configuredRootCredentialsDoNotImplicitlySignin(): void
    {
        $transport = new FakeSurrealTransport([['ok' => true]]);
        $client = new SurrealClient(new SurrealConfig(
            namespace: 'olympus',
            database: 'pantheon',
            username: 'root',
            password: 'root',
        ), $transport);

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $client->select($scope, 'goddess'),
            'test.surreal.client.root-credentials',
        );

        self::assertSame(['select'], array_column($transport->calls, 'method'));
        self::assertNull($transport->calls[0]['token']);
    }

    #[Test]
    public function missingCredentialsFailWhenDefaultSigninRequested(): void
    {
        $client = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
            new FakeSurrealTransport([]),
        );

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal credentials are not configured.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $client->signin($scope),
            'test.surreal.client.signin-missing-credentials',
        );
    }

    #[Test]
    public function nonTokenSigninResponseFails(): void
    {
        $client = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
            new FakeSurrealTransport([['ok' => true]]),
        );

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal signin returned a non-token response.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $client->signin($scope, ['user' => 'athena']),
            'test.surreal.client.signin-non-token',
        );
    }

    #[Test]
    public function authenticateAndInvalidateUpdateTokenState(): void
    {
        $transport = new FakeSurrealTransport([null, ['ok' => true], null, ['ok' => true]]);
        $client = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
            $transport,
        );

        $this->scope->run(
            static function (ExecutionScope $scope) use ($client): void {
                $client->authenticate($scope, 'jwt-token');
                $client->select($scope, 'goddess');
                $client->invalidate($scope);
                $client->select($scope, 'goddess');
            },
            'test.surreal.client.token-state',
        );

        self::assertSame(['authenticate', 'select', 'invalidate', 'select'], array_column($transport->calls, 'method'));
        self::assertNull($transport->calls[0]['token']);
        self::assertSame('jwt-token', $transport->calls[1]['token']);
        self::assertSame('jwt-token', $transport->calls[2]['token']);
        self::assertNull($transport->calls[3]['token']);
    }

    #[Test]
    public function statusAndHealthUseCurrentTokenWithoutImplicitSignin(): void
    {
        $transport = new FakeSurrealTransport([]);
        $client = new SurrealClient(
            new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt-token'),
            $transport,
        );

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): array => [
                'status' => $client->status($scope),
                'health' => $client->health($scope),
            ],
            'test.surreal.client.status-health',
        );

        self::assertSame(['status' => 200, 'health' => 200], $result);
        self::assertSame(['status', 'health'], array_column($transport->calls, 'method'));
        self::assertSame(['jwt-token', 'jwt-token'], array_column($transport->calls, 'token'));
    }
}

final class FakeSurrealTransport implements SurrealTransport
{
    /** @var list<array{method: string, params: list<mixed>, namespace: string, database: string, token: ?string}> */
    public array $calls = [];

    /** @param list<mixed> $responses */
    public function __construct(
        private array $responses,
    ) {
    }

    public function rpc(
        ExecutionScope $scope,
        SurrealConfig $config,
        ?string $token,
        string $method,
        array $params = [],
    ): mixed {
        $this->calls[] = [
            'method' => $method,
            'params' => $params,
            'namespace' => $config->namespace,
            'database' => $config->database,
            'token' => $token,
        ];

        return array_shift($this->responses);
    }

    public function status(ExecutionScope $scope, SurrealConfig $config, ?string $token): int
    {
        $this->calls[] = [
            'method' => 'status',
            'params' => [],
            'namespace' => $config->namespace,
            'database' => $config->database,
            'token' => $token,
        ];

        return 200;
    }

    public function health(ExecutionScope $scope, SurrealConfig $config, ?string $token): int
    {
        $this->calls[] = [
            'method' => 'health',
            'params' => [],
            'namespace' => $config->namespace,
            'database' => $config->database,
            'token' => $token,
        ];

        return 200;
    }
}
