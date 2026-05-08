<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealConfig;
use Phalanx\Surreal\SurrealException;
use Phalanx\Surreal\SurrealTransport;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealTest extends PhalanxTestCase
{
    #[Test]
    public function queryUnwrapsStatementResultsAndMergesLetParameters(): void
    {
        $transport = new FakeSurrealTransport([
            [['status' => 'OK', 'result' => [['id' => 'goddess:athena']]]],
        ]);
        $query = 'SELECT * FROM goddess WHERE name = $topic';

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $query): mixed {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                    $scope,
                );
                $surreal->let('topic', 'Athena');

                return $surreal->query($query);
            },
            'test.surreal.query',
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

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                    $scope,
                );

                $surreal->select('goddess:athena');
                $surreal->create('goddess', ['name' => 'Athena']);
                $surreal->insert('goddess', [['name' => 'Athena']]);
                $surreal->insertRelation('guides', ['in' => 'goddess:athena', 'out' => 'city:athens']);
                $surreal->update('goddess:athena', ['domain' => 'wisdom']);
                $surreal->upsert('goddess:athena', ['domain' => 'strategy']);
                $surreal->merge('goddess:athena', ['symbol' => 'aegis']);
                $surreal->patch(
                    'goddess:athena',
                    [['op' => 'replace', 'path' => '/domain', 'value' => 'strategy']],
                    diff: true,
                );
                $surreal->delete('goddess:athena');
                $surreal->relate(['goddess:athena'], 'guides', ['city:athens'], ['lesson' => 'strategy']);
                $surreal->run('fn::wisdom', '1.0.0', ['Athena']);
            },
            'test.surreal.records',
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
    public function connectionMethodsMapToSurrealRpcMethods(): void
    {
        $transport = new FakeSurrealTransport([null, null, null, ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                    $scope,
                );

                $surreal->ping();
                $surreal->kill('0189d6e3-8eac-703a-9a48-d9faa78b44b9');
                $surreal->use('athens', 'academy');
                $surreal->select('goddess:athena');
            },
            'test.surreal.connection-methods',
        );

        self::assertSame(['ping', 'kill', 'use', 'select'], array_column($transport->calls, 'method'));
        self::assertSame(['0189d6e3-8eac-703a-9a48-d9faa78b44b9'], $transport->calls[1]['params']);
        self::assertSame(['athens', 'academy'], $transport->calls[2]['params']);
        self::assertSame('athens', $transport->calls[3]['namespace']);
        self::assertSame('academy', $transport->calls[3]['database']);
    }

    #[Test]
    public function resetClearsTokenAndLocalQueryVariables(): void
    {
        $query = 'SELECT * FROM goddess WHERE name = $topic';
        $transport = new FakeSurrealTransport([
            null,
            [['status' => 'OK', 'result' => []]],
        ]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $query): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                    $scope,
                );

                $surreal->let('topic', 'Athena');
                $surreal->reset();
                $surreal->query($query);
            },
            'test.surreal.reset-state',
        );

        self::assertSame(['reset', 'query'], array_column($transport->calls, 'method'));
        self::assertSame('jwt', $transport->calls[0]['token']);
        self::assertNull($transport->calls[1]['token']);
        self::assertSame([$query], $transport->calls[1]['params']);
    }

    #[Test]
    public function withDatabaseDoesNotMutateBaseClient(): void
    {
        $transport = new FakeSurrealTransport([['ok' => true], ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $base = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                    $scope,
                );
                $alternate = $base->withDatabase('athens', 'library');

                $alternate->select('scroll');
                $base->select('goddess');
            },
            'test.surreal.with-database',
        );

        self::assertSame('athens', $transport->calls[0]['namespace']);
        self::assertSame('library', $transport->calls[0]['database']);
        self::assertSame('olympus', $transport->calls[1]['namespace']);
        self::assertSame('pantheon', $transport->calls[1]['database']);
    }

    #[Test]
    public function signinCachesTokenWhenSurrealReturnsRecordToken(): void
    {
        $transport = new FakeSurrealTransport([['token' => 'jwt-token'], ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                    $scope,
                );
                $token = $surreal->signin([
                    'NS' => 'olympus',
                    'DB' => 'pantheon',
                    'AC' => 'reader',
                    'user' => 'athena',
                    'pass' => 'shield',
                ]);

                self::assertSame('jwt-token', $token);
                $surreal->select('goddess');
            },
            'test.surreal.record-signin',
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
    public function signupCachesTokenWhenSurrealReturnsRecordToken(): void
    {
        $transport = new FakeSurrealTransport([['token' => 'jwt-token'], ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                    $scope,
                );
                $token = $surreal->signup([
                    'NS' => 'olympus',
                    'DB' => 'pantheon',
                    'AC' => 'reader',
                    'user' => 'athena',
                    'pass' => 'shield',
                ]);

                self::assertSame('jwt-token', $token);
                $surreal->select('goddess');
            },
            'test.surreal.record-signup',
        );

        self::assertSame(['signup', 'select'], array_column($transport->calls, 'method'));
        self::assertNull($transport->calls[0]['token']);
        self::assertSame('jwt-token', $transport->calls[1]['token']);
    }

    #[Test]
    public function configuredRootCredentialsDoNotImplicitlySignin(): void
    {
        $transport = new FakeSurrealTransport([['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(new SurrealConfig(
                    namespace: 'olympus',
                    database: 'pantheon',
                    username: 'root',
                    password: 'root',
                ), $transport, $scope);

                $surreal->select('goddess');
            },
            'test.surreal.root-credentials',
        );

        self::assertSame(['select'], array_column($transport->calls, 'method'));
        self::assertNull($transport->calls[0]['token']);
    }

    #[Test]
    public function missingCredentialsFailWhenDefaultSigninRequested(): void
    {
        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal credentials are not configured.');

        $this->scope->run(
            static function (ExecutionScope $scope): mixed {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealTransport([]),
                    $scope,
                );

                return $surreal->signin();
            },
            'test.surreal.signin-missing-credentials',
        );
    }

    #[Test]
    public function nonTokenSigninResponseFails(): void
    {
        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal signin returned a non-token response.');

        $this->scope->run(
            static function (ExecutionScope $scope): mixed {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    new FakeSurrealTransport([['ok' => true]]),
                    $scope,
                );

                return $surreal->signin(['user' => 'athena']);
            },
            'test.surreal.signin-non-token',
        );
    }

    #[Test]
    public function authenticateAndInvalidateUpdateTokenState(): void
    {
        $transport = new FakeSurrealTransport([null, ['ok' => true], null, ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                    $scope,
                );

                $surreal->authenticate('jwt-token');
                $surreal->select('goddess');
                $surreal->invalidate();
                $surreal->select('goddess');
            },
            'test.surreal.token-state',
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

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): array {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon', token: 'jwt-token'),
                    $transport,
                    $scope,
                );

                return [
                    'status' => $surreal->status(),
                    'health' => $surreal->health(),
                ];
            },
            'test.surreal.status-health',
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
        Scope&Suspendable $scope,
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

    public function status(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int
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

    public function health(Scope&Suspendable $scope, SurrealConfig $config, ?string $token): int
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
