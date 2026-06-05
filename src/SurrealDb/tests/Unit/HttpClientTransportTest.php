<?php

declare(strict_types=1);

namespace Phalanx\SurrealDb\Tests\Unit;

use Phalanx\HttpClient\HttpClient;
use Phalanx\HttpClient\HttpRequest;
use Phalanx\HttpClient\HttpResponse;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class HttpClientTransportTest extends PhalanxTestCase
{
    #[Test]
    public function rpcUsesHttpClientWithSurrealDbHeadersAndJsonEnvelope(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(
            '{"id":1,"result":[{"status":"OK","result":[{"id":"oracle:apollo"}]}]}',
        )]);
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http);
        $config = new \Phalanx\SurrealDb\Config(
            namespace: 'olympus',
            database: 'pantheon',
            endpoint: 'http://surrealdb.test:8000',
        );

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc(
                $scope,
                $config,
                'jwt-token',
                'query',
                ['SELECT * FROM oracle WHERE name = $name', ['name' => 'Apollo']],
            ),
            'test.surrealdb.transport.rpc',
        );

        self::assertSame([['status' => 'OK', 'result' => [['id' => 'oracle:apollo']]]], $result);
        $request = $http->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('http://surrealdb.test:8000/rpc', $request->url);
        self::assertSame(['olympus'], $request->headers['surrealdb-ns']);
        self::assertSame(['pantheon'], $request->headers['surrealdb-db']);
        self::assertSame(['Bearer jwt-token'], $request->headers['authorization']);
        self::assertJsonStringEqualsJsonString(
            '{"id":1,"method":"query","params":["SELECT * FROM oracle WHERE name = $name",{"name":"Apollo"}]}',
            (string) $request->body,
        );
    }

    #[Test]
    public function rootCredentialsUseBasicAuthWhenNoBearerTokenExists(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse('{"id":1,"result":{"ok":true}}')]);
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http);
        $config = new \Phalanx\SurrealDb\Config(
            namespace: 'olympus',
            database: 'pantheon',
            username: 'root',
            password: 'secret',
        );

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surrealdb.transport.basic-auth',
        );

        self::assertSame(['Basic ' . base64_encode('root:secret')], $http->requests[0]->headers['authorization']);
    }

    #[Test]
    public function bearerTokenTakesPrecedenceOverBasicAuth(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse('{"id":1,"result":{"ok":true}}')]);
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http);
        $config = new \Phalanx\SurrealDb\Config(
            namespace: 'olympus',
            database: 'pantheon',
            username: 'root',
            password: 'secret',
        );

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc(
                $scope,
                $config,
                'jwt-token',
                'select',
                ['oracle'],
            ),
            'test.surrealdb.transport.bearer-auth',
        );

        self::assertSame(['Bearer jwt-token'], $http->requests[0]->headers['authorization']);
    }

    #[Test]
    public function unauthenticatedRequestsDoNotSendAuthorizationHeader(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse('{"id":1,"result":{"ok":true}}')]);
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http);
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surrealdb.transport.no-auth',
        );

        self::assertArrayNotHasKey('authorization', $http->requests[0]->headers);
    }

    #[Test]
    public function rpcErrorEnvelopeThrowsException(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([self::jsonResponse(
            '{"id":1,"error":{"code":-32000,"message":"bad query"}}',
        )]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('bad query');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['BAD']),
            'test.surrealdb.transport.error',
        );
    }

    #[Test]
    public function rpcResponseWithoutIdIsAcceptedWhenResultEnvelopeIsValid(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([self::jsonResponse(
            '{"result":{"ok":true}}',
        )]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surrealdb.transport.result-without-id',
        );

        self::assertSame(['ok' => true], $result);
    }

    #[Test]
    public function rpcResponseIdMismatchThrowsException(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([self::jsonResponse(
            '{"id":2,"result":{"ok":true}}',
        )]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb RPC response id mismatch: expected 1.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surrealdb.transport.id-mismatch',
        );
    }

    #[Test]
    public function malformedRpcEnvelopeThrowsException(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([self::jsonResponse('{"id":1}')]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb RPC response was missing result or error.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surrealdb.transport.malformed-envelope',
        );
    }

    #[Test]
    public function nonObjectRpcEnvelopeThrowsException(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([self::jsonResponse('"ok"')]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb RPC response was not a JSON object.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surrealdb.transport.non-object-envelope',
        );
    }

    #[Test]
    public function listRpcEnvelopeThrowsException(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([self::jsonResponse('[{"result":true}]')]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb RPC response was not a JSON object.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surrealdb.transport.list-envelope',
        );
    }

    #[Test]
    public function nonSuccessfulHttpResponseThrowsException(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([new HttpResponse(
            status: 500,
            reasonPhrase: 'Internal Server Error',
            headers: [],
            body: 'broken',
        )]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('SurrealDb HTTP request failed with status 500.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['BAD']),
            'test.surrealdb.transport.http-error',
        );
    }

    #[Test]
    public function invalidJsonResponseThrowsException(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([new HttpResponse(
            status: 200,
            reasonPhrase: 'OK',
            headers: ['content-type' => ['application/json']],
            body: '{',
        )]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->expectException(\Phalanx\SurrealDb\Exception::class);
        $this->expectExceptionMessage('Failed to decode SurrealDb response:');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['BAD']),
            'test.surrealdb.transport.invalid-json',
        );
    }

    #[Test]
    public function emptySuccessfulResponseReturnsNull(): void
    {
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport(new RecordingHttpClient([new HttpResponse(
            status: 204,
            reasonPhrase: 'No Content',
            headers: [],
            body: '',
        )]));
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['RETURN NONE']),
            'test.surrealdb.transport.empty-response',
        );

        self::assertNull($result);
    }

    #[Test]
    public function rpcIdsIncrementForEachRequest(): void
    {
        $http = new RecordingHttpClient([
            self::jsonResponse('{"id":1,"result":{"ok":true}}'),
            self::jsonResponse('{"id":2,"result":{"ok":true}}'),
        ]);
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http);
        $config = new \Phalanx\SurrealDb\Config(namespace: 'olympus', database: 'pantheon');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $config): void {
                $transport->rpc($scope, $config, null, 'select', ['oracle']);
                $transport->rpc($scope, $config, null, 'select', ['city']);
            },
            'test.surrealdb.transport.request-ids',
        );

        self::assertJsonStringEqualsJsonString(
            '{"id":1,"method":"select","params":["oracle"]}',
            (string) $http->requests[0]->body,
        );
        self::assertJsonStringEqualsJsonString(
            '{"id":2,"method":"select","params":["city"]}',
            (string) $http->requests[1]->body,
        );
    }

    #[Test]
    public function statusAndHealthUseGetEndpoints(): void
    {
        $http = new RecordingHttpClient([
            new HttpResponse(status: 204, reasonPhrase: 'No Content', headers: [], body: ''),
            new HttpResponse(status: 200, reasonPhrase: 'OK', headers: [], body: ''),
        ]);
        $transport = new \Phalanx\SurrealDb\Transport\HttpClient\Transport($http);
        $config = new \Phalanx\SurrealDb\Config(
            namespace: 'olympus',
            database: 'pantheon',
            endpoint: 'http://surrealdb.test:8000',
        );

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): array => [
                'status' => $transport->status($scope, $config, 'jwt-token'),
                'health' => $transport->health($scope, $config, 'jwt-token'),
            ],
            'test.surrealdb.transport.status-health',
        );

        self::assertSame(['status' => 204, 'health' => 200], $result);
        self::assertSame('GET', $http->requests[0]->method);
        self::assertSame('http://surrealdb.test:8000/status', $http->requests[0]->url);
        self::assertSame('GET', $http->requests[1]->method);
        self::assertSame('http://surrealdb.test:8000/health', $http->requests[1]->url);
        self::assertSame(['Bearer jwt-token'], $http->requests[1]->headers['authorization']);
    }

    private static function jsonResponse(string $body): HttpResponse
    {
        return new HttpResponse(
            status: 200,
            reasonPhrase: 'OK',
            headers: ['content-type' => ['application/json']],
            body: $body,
        );
    }
}

final class RecordingHttpClient extends HttpClient
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @param list<HttpResponse> $responses */
    public function __construct(
        private array $responses,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function request(Scope&Suspendable $scope, HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        return array_shift($this->responses) ?? new HttpResponse(
            status: 500,
            reasonPhrase: 'Missing Test Response',
            headers: [],
            body: '',
        );
    }
}
