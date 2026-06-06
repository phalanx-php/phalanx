<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Phalanx\Scope\ExecutionScope;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class ClientTest extends PhalanxTestCase
{
    #[Test]
    public function queryUnwrapsStatementResultsAndMergesLetParameters(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([
            [['status' => 'OK', 'result' => [['id' => 'oracle:apollo']]]],
        ]);
        $query = 'SELECT * FROM oracle WHERE name = $topic';

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $query): mixed {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                );
                $surrealdb->let('topic', 'Apollo');

                return $surrealdb->query($query);
            },
            'test.surrealdb.query',
        );

        self::assertSame([[['id' => 'oracle:apollo']]], $result);
        self::assertSame('query', $transport->calls[0]['method']);
        self::assertSame(
            [$query, ['topic' => 'Apollo']],
            $transport->calls[0]['params'],
        );
    }

    #[Test]
    public function recordMethodsMapToSurrealDbRpcMethods(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport(array_fill(0, 11, ['ok' => true]));

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                );

                $surrealdb->select('oracle:apollo');
                $surrealdb->create('oracle', ['name' => 'Apollo']);
                $surrealdb->insert('oracle', [['name' => 'Apollo']]);
                $surrealdb->insertRelation('guides', ['in' => 'oracle:apollo', 'out' => 'city:delphi']);
                $surrealdb->update('oracle:apollo', ['domain' => 'prophecy']);
                $surrealdb->upsert('oracle:apollo', ['domain' => 'strategy']);
                $surrealdb->merge('oracle:apollo', ['symbol' => 'laurel']);
                $surrealdb->patch(
                    'oracle:apollo',
                    [['op' => 'replace', 'path' => '/domain', 'value' => 'strategy']],
                    diff: true,
                );
                $surrealdb->delete('oracle:apollo');
                $surrealdb->relate(['oracle:apollo'], 'guides', ['city:delphi'], ['lesson' => 'strategy']);
                $surrealdb->run('fn::prophecy', '1.0.0', ['Apollo']);
            },
            'test.surrealdb.records',
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
    public function connectionMethodsMapToSurrealDbRpcMethods(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([null, null, null, ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                );

                $surrealdb->ping();
                $surrealdb->kill('0189d6e3-8eac-703a-9a48-d9faa78b44b9');
                $surrealdb->use('athens', 'academy');
                $surrealdb->select('oracle:apollo');
            },
            'test.surrealdb.connection-methods',
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
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([
            null,
            [['status' => 'OK', 'result' => []]],
        ]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $query): void {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                );

                $surrealdb->let('topic', 'Apollo');
                $surrealdb->reset();
                $surrealdb->query($query);
            },
            'test.surrealdb.reset-state',
        );

        self::assertSame(['reset', 'query'], array_column($transport->calls, 'method'));
        self::assertSame('jwt', $transport->calls[0]['token']);
        self::assertNull($transport->calls[1]['token']);
        self::assertSame([$query], $transport->calls[1]['params']);
    }

    #[Test]
    public function withDatabaseDoesNotMutateBaseClient(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([['ok' => true], ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $base = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon', token: 'jwt'),
                    $transport,
                );
                $alternate = $base->withDatabase('athens', 'library');

                $alternate->select('scroll');
                $base->select('oracle');
            },
            'test.surrealdb.with-database',
        );

        self::assertSame('athens', $transport->calls[0]['namespace']);
        self::assertSame('library', $transport->calls[0]['database']);
        self::assertSame('olympus', $transport->calls[1]['namespace']);
        self::assertSame('pantheon', $transport->calls[1]['database']);
    }

    #[Test]
    public function signinCachesTokenWhenSurrealDbReturnsRecordToken(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([['token' => 'jwt-token'], ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                );
                $token = $surrealdb->signin([
                    'NS' => 'olympus',
                    'DB' => 'pantheon',
                    'AC' => 'reader',
                    'user' => 'apollo',
                    'pass' => 'shield',
                ]);

                self::assertSame('jwt-token', $token);
                $surrealdb->select('oracle');
            },
            'test.surrealdb.record-signin',
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
    public function signupCachesTokenWhenSurrealDbReturnsRecordToken(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([['token' => 'jwt-token'], ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                );
                $token = $surrealdb->signup([
                    'NS' => 'olympus',
                    'DB' => 'pantheon',
                    'AC' => 'reader',
                    'user' => 'apollo',
                    'pass' => 'shield',
                ]);

                self::assertSame('jwt-token', $token);
                $surrealdb->select('oracle');
            },
            'test.surrealdb.record-signup',
        );

        self::assertSame(['signup', 'select'], array_column($transport->calls, 'method'));
        self::assertNull($transport->calls[0]['token']);
        self::assertSame('jwt-token', $transport->calls[1]['token']);
    }

    #[Test]
    public function configuredRootCredentialsDoNotImplicitlySignin(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(
                        namespace: 'olympus',
                        database: 'pantheon',
                        username: 'root',
                        password: 'root',
                    ),
                    $transport,
                );

                $surrealdb->select('oracle');
            },
            'test.surrealdb.root-credentials',
        );

        self::assertSame(['select'], array_column($transport->calls, 'method'));
        self::assertNull($transport->calls[0]['token']);
    }

    #[Test]
    public function missingCredentialsFailWhenDefaultSigninRequested(): void
    {
        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb credentials are not configured.');

        $this->scope->run(
            static function (ExecutionScope $scope): mixed {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon'),
                    new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([]),
                );

                return $surrealdb->signin();
            },
            'test.surrealdb.signin-missing-credentials',
        );
    }

    #[Test]
    public function nonTokenSigninResponseFails(): void
    {
        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb signin returned a non-token response.');

        $this->scope->run(
            static function (ExecutionScope $scope): mixed {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon'),
                    new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([['ok' => true]]),
                );

                return $surrealdb->signin(['user' => 'apollo']);
            },
            'test.surrealdb.signin-non-token',
        );
    }

    #[Test]
    public function authenticateAndInvalidateUpdateTokenState(): void
    {
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([null, ['ok' => true], null, ['ok' => true]]);

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): void {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon'),
                    $transport,
                );

                $surrealdb->authenticate('jwt-token');
                $surrealdb->select('oracle');
                $surrealdb->invalidate();
                $surrealdb->select('oracle');
            },
            'test.surrealdb.token-state',
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
        $transport = new \Phalanx\SurrealDb\Tests\Unit\FakeTransport([]);

        $result = $this->scope->run(
            static function (ExecutionScope $scope) use ($transport): array {
                $surrealdb = new \Phalanx\SurrealDb\Client(
                    $scope,
                    new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon', token: 'jwt-token'),
                    $transport,
                );

                return [
                    'status' => $surrealdb->status(),
                    'health' => $surrealdb->health(),
                ];
            },
            'test.surrealdb.status-health',
        );

        self::assertSame(['status' => 200, 'health' => 200], $result);
        self::assertSame(['status', 'health'], array_column($transport->calls, 'method'));
        self::assertSame(['jwt-token', 'jwt-token'], array_column($transport->calls, 'token'));
    }
}
