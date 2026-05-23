<?php

declare(strict_types=1);

namespace Phalanx\Surreal\Tests\Unit;

use Phalanx\Iris\HttpClient;
use Phalanx\Iris\HttpRequest;
use Phalanx\Iris\HttpResponse;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\Suspendable;
use Phalanx\Surreal\IrisSurrealTransport;
use Phalanx\Surreal\SurrealConfig;
use Phalanx\Surreal\SurrealException;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;

final class IrisSurrealTransportTest extends PhalanxTestCase
{
    #[Test]
    public function rpcUsesIrisWithSurrealHeadersAndJsonEnvelope(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse(
            '{"id":1,"result":[{"status":"OK","result":[{"id":"oracle:apollo"}]}]}',
        )]);
        $transport = new IrisSurrealTransport($http);
        $config = new SurrealConfig(
            namespace: 'olympus',
            database: 'pantheon',
            endpoint: 'http://surreal.test:8000',
        );

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc(
                $scope,
                $config,
                'jwt-token',
                'query',
                ['SELECT * FROM oracle WHERE name = $name', ['name' => 'Apollo']],
            ),
            'test.surreal.transport.rpc',
        );

        self::assertSame([['status' => 'OK', 'result' => [['id' => 'oracle:apollo']]]], $result);
        $request = $http->requests[0];
        self::assertSame('POST', $request->method);
        self::assertSame('http://surreal.test:8000/rpc', $request->url);
        self::assertSame(['olympus'], $request->headers['surreal-ns']);
        self::assertSame(['pantheon'], $request->headers['surreal-db']);
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
        $transport = new IrisSurrealTransport($http);
        $config = new SurrealConfig(
            namespace: 'olympus',
            database: 'pantheon',
            username: 'root',
            password: 'secret',
        );

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surreal.transport.basic-auth',
        );

        self::assertSame(['Basic ' . base64_encode('root:secret')], $http->requests[0]->headers['authorization']);
    }

    #[Test]
    public function bearerTokenTakesPrecedenceOverBasicAuth(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse('{"id":1,"result":{"ok":true}}')]);
        $transport = new IrisSurrealTransport($http);
        $config = new SurrealConfig(
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
            'test.surreal.transport.bearer-auth',
        );

        self::assertSame(['Bearer jwt-token'], $http->requests[0]->headers['authorization']);
    }

    #[Test]
    public function unauthenticatedRequestsDoNotSendAuthorizationHeader(): void
    {
        $http = new RecordingHttpClient([self::jsonResponse('{"id":1,"result":{"ok":true}}')]);
        $transport = new IrisSurrealTransport($http);
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surreal.transport.no-auth',
        );

        self::assertArrayNotHasKey('authorization', $http->requests[0]->headers);
    }

    #[Test]
    public function rpcErrorEnvelopeThrowsSurrealException(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([self::jsonResponse(
            '{"id":1,"error":{"code":-32000,"message":"bad query"}}',
        )]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('bad query');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['BAD']),
            'test.surreal.transport.error',
        );
    }

    #[Test]
    public function rpcResponseWithoutIdIsAcceptedWhenResultEnvelopeIsValid(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([self::jsonResponse(
            '{"result":{"ok":true}}',
        )]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surreal.transport.result-without-id',
        );

        self::assertSame(['ok' => true], $result);
    }

    #[Test]
    public function rpcResponseIdMismatchThrowsSurrealException(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([self::jsonResponse(
            '{"id":2,"result":{"ok":true}}',
        )]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal RPC response id mismatch: expected 1.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surreal.transport.id-mismatch',
        );
    }

    #[Test]
    public function malformedRpcEnvelopeThrowsSurrealException(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([self::jsonResponse('{"id":1}')]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal RPC response was missing result or error.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surreal.transport.malformed-envelope',
        );
    }

    #[Test]
    public function nonObjectRpcEnvelopeThrowsSurrealException(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([self::jsonResponse('"ok"')]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal RPC response was not a JSON object.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surreal.transport.non-object-envelope',
        );
    }

    #[Test]
    public function listRpcEnvelopeThrowsSurrealException(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([self::jsonResponse('[{"result":true}]')]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal RPC response was not a JSON object.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'select', ['oracle']),
            'test.surreal.transport.list-envelope',
        );
    }

    #[Test]
    public function nonSuccessfulHttpResponseThrowsSurrealException(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([new HttpResponse(
            status: 500,
            reasonPhrase: 'Internal Server Error',
            headers: [],
            body: 'broken',
        )]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Surreal HTTP request failed with status 500.');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['BAD']),
            'test.surreal.transport.http-error',
        );
    }

    #[Test]
    public function invalidJsonResponseThrowsSurrealException(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([new HttpResponse(
            status: 200,
            reasonPhrase: 'OK',
            headers: ['content-type' => ['application/json']],
            body: '{',
        )]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->expectException(SurrealException::class);
        $this->expectExceptionMessage('Failed to decode Surreal response:');

        $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['BAD']),
            'test.surreal.transport.invalid-json',
        );
    }

    #[Test]
    public function emptySuccessfulResponseReturnsNull(): void
    {
        $transport = new IrisSurrealTransport(new RecordingHttpClient([new HttpResponse(
            status: 204,
            reasonPhrase: 'No Content',
            headers: [],
            body: '',
        )]));
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): mixed => $transport->rpc($scope, $config, null, 'query', ['RETURN NONE']),
            'test.surreal.transport.empty-response',
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
        $transport = new IrisSurrealTransport($http);
        $config = new SurrealConfig(namespace: 'olympus', database: 'pantheon');

        $this->scope->run(
            static function (ExecutionScope $scope) use ($transport, $config): void {
                $transport->rpc($scope, $config, null, 'select', ['oracle']);
                $transport->rpc($scope, $config, null, 'select', ['city']);
            },
            'test.surreal.transport.request-ids',
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
        $transport = new IrisSurrealTransport($http);
        $config = new SurrealConfig(
            namespace: 'olympus',
            database: 'pantheon',
            endpoint: 'http://surreal.test:8000',
        );

        $result = $this->scope->run(
            static fn(ExecutionScope $scope): array => [
                'status' => $transport->status($scope, $config, 'jwt-token'),
                'health' => $transport->health($scope, $config, 'jwt-token'),
            ],
            'test.surreal.transport.status-health',
        );

        self::assertSame(['status' => 204, 'health' => 200], $result);
        self::assertSame('GET', $http->requests[0]->method);
        self::assertSame('http://surreal.test:8000/status', $http->requests[0]->url);
        self::assertSame('GET', $http->requests[1]->method);
        self::assertSame('http://surreal.test:8000/health', $http->requests[1]->url);
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
