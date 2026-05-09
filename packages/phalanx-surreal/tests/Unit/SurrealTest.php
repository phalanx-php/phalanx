<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Surreal\Surreal;
use Phalanx\Surreal\SurrealConfig;
use Phalanx\Surreal\SurrealException;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class SurrealTest extends PhalanxTestCase
{
    #[Test]
    public function queryUnwrapsStatementResultsAndMergesLetParameters(): void
    {
        $transport = new FakeSurrealTransport([
            [['status' => 'OK', 'result' => [['id' => 'oracle:apollo']]]],
        ]);
        $query = 'SELECT * FROM oracle WHERE name = $topic';

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $query): mixed {
                $surreal = new Surreal(
                    new SurrealConfig(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                    $scope,
                );
                $surreal->let('topic', 'Apollo');

                return $surreal->query($query);
            },
            'test.surreal.query',
        );

        self::assertSame([[['id' => 'oracle:apollo']]], $result);
        self::assertSame('query', $transport->calls[0]['method']);
        self::assertSame(
            [$query, ['topic' => 'Apollo']],
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

                $surreal->select('oracle:apollo');
                $surreal->create('oracle', ['name' => 'Apollo']);
                $surreal->insert('oracle', [['name' => 'Apollo']]);
                $surreal->insertRelation('guides', ['in' => 'oracle:apollo', 'out' => 'city:delphi']);
                $surreal->update('oracle:apollo', ['domain' => 'prophecy']);
                $surreal->upsert('oracle:apollo', ['domain' => 'strategy']);
                $surreal->merge('oracle:apollo', ['symbol' => 'laurel']);
                $surreal->patch(
                    'oracle:apollo',
                    [['op' => 'replace', 'path' => '/domain', 'value' => 'strategy']],
                    diff: true,
                );
                $surreal->delete('oracle:apollo');
                $surreal->relate(['oracle:apollo'], 'guides', ['city:delphi'], ['lesson' => 'strategy']);
                $surreal->run('fn::prophecy', '1.0.0', ['Apollo']);
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
        self::assertSame(['oracle:apollo'], $transport->calls[0]['params']);
        self::assertSame(
            ['oracle:apollo', [['op' => 'replace', 'path' => '/domain', 'value' => 'strategy']], true],
            $transport->calls[7]['params'],
        );
        self::assertSame(
            [['oracle:apollo'], 'guides', ['city:delphi'], ['lesson' => 'strategy']],
            $transport->calls[9]['params'],
        );
        self::assertSame(['fn::prophecy', '1.0.0', ['Apollo']], $transport->calls[10]['params']);
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
                $surreal->select('oracle:apollo');
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
        $query = 'SELECT * FROM oracle WHERE name = $topic';
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

                $surreal->let('topic', 'Apollo');
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
                $base->select('oracle');
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
                    'user' => 'apollo',
                    'pass' => 'shield',
                ]);

                self::assertSame('jwt-token', $token);
                $surreal->select('oracle');
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
                'user' => 'apollo',
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
                    'user' => 'apollo',
                    'pass' => 'shield',
                ]);

                self::assertSame('jwt-token', $token);
                $surreal->select('oracle');
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

                $surreal->select('oracle');
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

                return $surreal->signin(['user' => 'apollo']);
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
                $surreal->select('oracle');
                $surreal->invalidate();
                $surreal->select('oracle');
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
