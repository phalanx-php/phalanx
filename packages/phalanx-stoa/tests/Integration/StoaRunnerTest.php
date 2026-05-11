<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use OpenSwoole\Http\Request as OpenSwooleRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Stoa\ExecutionContext;
use Phalanx\Stoa\MissingRequestResource;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\Runtime\Identity\StoaAnnotationSid;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\Runtime\Identity\StoaResourceSid;
use Phalanx\Stoa\StoaRequestFactory;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class StoaRunnerTest extends PhalanxTestCase
{
    #[Test]
    public function stoa_runtime_identities_are_typed_and_stable(): void
    {
        self::assertSame('HttpRequest', StoaResourceSid::HttpRequest->key());
        self::assertSame('stoa.http_request', StoaResourceSid::HttpRequest->value());
        self::assertSame('Route', StoaAnnotationSid::Route->key());
        self::assertSame('stoa.route', StoaAnnotationSid::Route->value());
        self::assertSame('RouteMatched', StoaEventSid::RouteMatched->key());
        self::assertSame('stoa.route_matched', StoaEventSid::RouteMatched->value());
        self::assertInstanceOf(RuntimeResourceId::class, StoaResourceSid::HttpRequest);
        self::assertInstanceOf(RuntimeAnnotationId::class, StoaAnnotationSid::Route);
        self::assertInstanceOf(RuntimeEventId::class, StoaEventSid::RouteMatched);
    }

    #[Test]
    public function dispatches_plaintext_route_and_disposes_scope(): void
    {
        [$response, $activeRequests] = $this->withStoaRunner(RouteGroup::of([
            'GET /plaintext' => PlainTextStoaRoute::class,
        ]), static function (StoaRunner $runner): array {
            $response = $runner->dispatch(new ServerRequest('GET', '/plaintext'));

            return [$response, $runner->activeRequests()];
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('stoa-ok', (string) $response->getBody());
        self::assertTrue(PlainTextStoaRoute::$disposed);
        self::assertSame(0, $activeRequests);
    }

    #[Test]
    public function head_request_uses_get_route_without_response_body(): void
    {
        $response = $this->withStoaRunner(RouteGroup::of([
            'GET /head' => HeadStoaRoute::class,
        ]), static fn(StoaRunner $runner) => $runner->dispatch(new ServerRequest('HEAD', '/head')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Head-Proof'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function no_content_and_not_modified_responses_do_not_expose_bodies(): void
    {
        [$empty, $cached] = $this->withStoaRunner(RouteGroup::of([
            'GET /empty' => NoContentStoaRoute::class,
            'GET /cached' => NotModifiedStoaRoute::class,
        ]), static function (StoaRunner $runner): array {
            $empty = $runner->dispatch(new ServerRequest('GET', '/empty'));
            $cached = $runner->dispatch(new ServerRequest('GET', '/cached'));

            return [$empty, $cached];
        });

        self::assertSame(204, $empty->getStatusCode());
        self::assertSame('', (string) $empty->getBody());
        self::assertSame(304, $cached->getStatusCode());
        self::assertSame('', (string) $cached->getBody());
    }

    #[Test]
    public function powered_by_header_is_defaulted_preserved_or_disabled(): void
    {
        $default = $this->withStoaRunner(RouteGroup::of([
            'GET /plaintext' => PlainTextStoaRoute::class,
        ]), static fn(StoaRunner $runner) => $runner->dispatch(new ServerRequest('GET', '/plaintext')));

        $custom = $this->withStoaRunner(RouteGroup::of([
            'GET /powered' => ExistingPoweredByStoaRoute::class,
        ]), static fn(StoaRunner $runner) => $runner->dispatch(new ServerRequest('GET', '/powered')));

        $disabled = $this->withStoaRunner(
            RouteGroup::of([
                'GET /plaintext' => PlainTextStoaRoute::class,
            ]),
            static fn(StoaRunner $runner) => $runner->dispatch(new ServerRequest('GET', '/plaintext')),
            new StoaServerConfig(poweredBy: null),
        );

        self::assertSame('Phalanx', $default->getHeaderLine('X-Powered-By'));
        self::assertSame('Existing', $custom->getHeaderLine('X-Powered-By'));
        self::assertFalse($disabled->hasHeader('X-Powered-By'));
    }

    #[Test]
    public function dispatches_json_route_through_existing_route_scope(): void
    {
        $response = $this->withStoaRunner(RouteGroup::of([
            'GET /json' => JsonStoaRoute::class,
        ]), static function (StoaRunner $runner) {
            $request = (new ServerRequest('GET', '/json?name=phalanx'))
                ->withQueryParams(['name' => 'phalanx']);

            return $runner->dispatch($request);
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['path' => '/json', 'name' => 'phalanx'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function request_scope_exposes_aegis_managed_resource_identity(): void
    {
        [$response, $body, $resourceEvents, $released] = $this->withStoaRunner(RouteGroup::of([
            'GET /resource/{id:int}' => ResourceAwareStoaRoute::class,
        ]), static function (StoaRunner $runner, Application $app): array {
            $response = $runner->dispatch(new ServerRequest('GET', '/resource/42'));
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $events = $app->runtime()->memory->events->recent();
            $resourceEvents = self::eventTypesForResource($events, (string) $body['resource_id']);
            $released = $app->runtime()->memory->resources->get((string) $body['resource_id']) === null;

            return [$response, $body, $resourceEvents, $released];
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($body['resource_id']);
        self::assertStringStartsWith('stoa-request-', $body['resource_id']);
        self::assertSame('/resource/{id:int}', $body['route']);
        self::assertSame('42', $body['param']);
        self::assertTrue($released);
        self::assertContains('resource.opened', $resourceEvents);
        self::assertContains(StoaEventSid::RouteMatched->value(), $resourceEvents);
        self::assertContains('resource.closed', $resourceEvents);
        self::assertContains('resource.released', $resourceEvents);
    }

    #[Test]
    public function long_request_path_is_bounded_in_runtime_annotations(): void
    {
        $path = '/long/' . str_repeat('x', 300);

        [$response, $body, $activeRequests, $liveRequests] = $this->withStoaRunner(RouteGroup::of([
            'GET /long/{slug}' => LongPathStoaRoute::class,
        ]), static function (StoaRunner $runner, Application $app) use ($path): array {
            $response = $runner->dispatch(new ServerRequest('GET', $path));
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $activeRequests = $runner->activeRequests();
            $liveRequests = $app->runtime()->memory->resources->liveCount(StoaResourceSid::HttpRequest);

            return [$response, $body, $activeRequests, $liveRequests];
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($path, $body['path']);
        self::assertSame(240, strlen((string) $body['path_annotation']));
        self::assertSame(0, $activeRequests);
        self::assertSame(0, $liveRequests);
    }

    #[Test]
    public function request_setup_failure_disposes_scope_and_leaves_runtime_clean(): void
    {
        [$caught, $activeRequests, $liveResources] = $this->withStoaRunner(RouteGroup::of([
            'GET /plaintext' => PlainTextStoaRoute::class,
        ]), static function (StoaRunner $runner, Application $app): array {
            $caught = null;

            try {
                $runner->dispatch(new ExplodingPathRequest());
            } catch (RuntimeException $e) {
                $caught = $e;
            }

            return [$caught, $runner->activeRequests(), $app->runtime()->memory->resources->liveCount()];
        });

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame(0, $activeRequests);
        self::assertSame(0, $liveResources);
    }

    #[Test]
    public function partial_request_resource_open_failure_releases_opened_resource(): void
    {
        $memory = new RuntimeMemory(new RuntimeMemoryConfig());
        $runtime = new RuntimeContext($memory);
        $request = new ExplodingPathRequest();
        $token = CancellationToken::create();
        $caught = null;

        try {
            try {
                StoaRequestResource::open($runtime, $request, $token);
            } catch (RuntimeException $e) {
                $caught = $e;
            }

            self::assertInstanceOf(RuntimeException::class, $caught);
            self::assertSame(0, $memory->resources->liveCount(StoaResourceSid::HttpRequest));
        } finally {
            $token->cancel();
            $memory->shutdown();
        }
    }

    #[Test]
    public function request_scope_resource_id_fails_loud_when_missing(): void
    {
        $this->expectException(MissingRequestResource::class);

        $this->scope->run(static function (): void {
            $app = Application::starting()->compile()->startup();
            $scope = $app->createScope();
            $context = new ExecutionContext(
                $scope,
                new ServerRequest('GET', '/missing-resource'),
                new RouteParams(),
                new QueryParams(),
                RouteConfig::compile('/missing-resource'),
            );

            try {
                self::assertSame('', $context->resourceId);
            } finally {
                $scope->dispose();
                $app->shutdown();
            }
        });
    }

    #[Test]
    public function disposes_scope_after_handler_exception(): void
    {
        [$response, $events] = $this->withStoaRunner(
            RouteGroup::of([
                'GET /fail' => FailingStoaRoute::class,
            ]),
            static function (StoaRunner $runner, Application $app): array {
                $response = $runner->dispatch(new ServerRequest('GET', '/fail'));
                $events = $app->runtime()->memory->events->recent();

                return [$response, $events];
            },
            new StoaServerConfig(ignitionEnabled: true),
        );

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Internal Server Error', $body['error']);
        self::assertSame('expected failure', $body['message']);
        self::assertSame('GET', $body['request']['method']);
        self::assertSame('/fail', $body['request']['path']);
        self::assertSame('failed', $body['request']['state']);
        self::assertIsString($body['tasks']);
        self::assertContains(
            StoaEventSid::RequestFailed->value(),
            self::eventTypesForResource($events, (string) $body['request']['id']),
        );
        self::assertContains(
            'resource.released',
            self::eventTypesForResource($events, (string) $body['request']['id']),
        );
        self::assertTrue(FailingStoaRoute::$disposed);
    }

    #[Test]
    public function translates_openswoole_request_to_psr_request(): void
    {
        $request = new OpenSwooleRequest();
        $request->server = [
            'request_method' => 'POST',
            'request_uri' => '/submit',
            'query_string' => 'page=2',
            'server_protocol' => 'HTTP/1.1',
            'remote_addr' => '127.0.0.1',
        ];
        $request->header = ['content-type' => 'application/json'];
        $request->get = ['page' => '2'];
        $request->cookie = ['sid' => 'abc'];
        $request->post = ['name' => 'Ada'];

        $psrRequest = (new StoaRequestFactory())->create($request);

        self::assertSame('POST', $psrRequest->getMethod());
        self::assertSame('/submit', $psrRequest->getUri()->getPath());
        self::assertSame(['page' => '2'], $psrRequest->getQueryParams());
        self::assertSame(['sid' => 'abc'], $psrRequest->getCookieParams());
        self::assertSame(['name' => 'Ada'], $psrRequest->getParsedBody());
        self::assertSame('application/json', $psrRequest->getHeaderLine('content-type'));
    }

    #[Test]
    public function translates_uploaded_files_from_openswoole_request(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'stoa-upload-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'upload-body');

        $request = new OpenSwooleRequest();
        $request->server = [
            'request_method' => 'POST',
            'request_uri' => '/upload',
            'query_string' => 'debug=1',
            'server_protocol' => 'HTTP/2',
            'remote_addr' => '192.0.2.10',
        ];
        $request->header = ['content-type' => 'application/json'];
        $request->get = ['debug' => '1'];
        $request->post = ['fallback' => 'form'];
        $request->files = [
            'avatar' => [
                'name' => 'avatar.txt',
                'type' => 'text/plain',
                'tmp_name' => $tmpFile,
                'error' => UPLOAD_ERR_OK,
                'size' => 11,
            ],
        ];

        try {
            $psrRequest = (new StoaRequestFactory())->create($request);
        } finally {
            @unlink($tmpFile);
        }

        self::assertSame('/upload', $psrRequest->getUri()->getPath());
        self::assertSame('debug=1', $psrRequest->getUri()->getQuery());
        self::assertSame('2', $psrRequest->getProtocolVersion());
        self::assertSame('192.0.2.10', $psrRequest->getServerParams()['remote_addr']);
        self::assertSame(['fallback' => 'form'], $psrRequest->getParsedBody());
        self::assertArrayHasKey('avatar', $psrRequest->getUploadedFiles());
        self::assertSame('avatar.txt', $psrRequest->getUploadedFiles()['avatar']->getClientFilename());
    }

    #[Test]
    public function translates_indexed_uploaded_file_list_from_openswoole_request(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'stoa-upload-a-');
        $second = tempnam(sys_get_temp_dir(), 'stoa-upload-b-');
        self::assertIsString($first);
        self::assertIsString($second);
        file_put_contents($first, 'first-body');
        file_put_contents($second, 'second-body');

        $request = new OpenSwooleRequest();
        $request->server = [
            'request_method' => 'POST',
            'request_uri' => '/uploads',
            'server_protocol' => 'HTTP/1.1',
        ];
        $request->files = [
            'attachments' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$first, $second],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK],
                'size' => [10, 11],
            ],
        ];

        try {
            $psrRequest = (new StoaRequestFactory())->create($request);
        } finally {
            @unlink($first);
            @unlink($second);
        }

        $uploads = $psrRequest->getUploadedFiles();
        self::assertArrayHasKey('attachments', $uploads);
        self::assertIsArray($uploads['attachments']);
        self::assertCount(2, $uploads['attachments']);
        self::assertSame('a.txt', $uploads['attachments'][0]->getClientFilename());
        self::assertSame('b.txt', $uploads['attachments'][1]->getClientFilename());
    }

    #[Test]
    public function defensively_skips_indexed_uploaded_files_with_missing_tmp_name(): void
    {
        $present = tempnam(sys_get_temp_dir(), 'stoa-upload-c-');
        self::assertIsString($present);
        file_put_contents($present, 'only-real');

        $request = new OpenSwooleRequest();
        $request->server = [
            'request_method' => 'POST',
            'request_uri' => '/uploads',
            'server_protocol' => 'HTTP/1.1',
        ];
        $request->files = [
            'attachments' => [
                'name' => ['real.txt', 'broken.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$present, null],
                'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
                'size' => [9, 0],
            ],
            'broken_single' => [
                'name' => 'b.txt',
                'tmp_name' => null,
                'error' => UPLOAD_ERR_NO_FILE,
                'size' => 0,
                'type' => 'text/plain',
            ],
        ];

        try {
            $psrRequest = (new StoaRequestFactory())->create($request);
        } finally {
            @unlink($present);
        }

        $uploads = $psrRequest->getUploadedFiles();
        self::assertArrayHasKey('attachments', $uploads);
        self::assertIsArray($uploads['attachments']);
        self::assertCount(1, $uploads['attachments']);
        self::assertSame('real.txt', $uploads['attachments'][0]->getClientFilename());
        self::assertArrayNotHasKey('broken_single', $uploads);
    }

    #[Test]
    public function preserves_psr_header_lookups_regardless_of_openswoole_header_case(): void
    {
        $request = new OpenSwooleRequest();
        $request->server = [
            'request_method' => 'GET',
            'request_uri' => '/headers',
            'server_protocol' => 'HTTP/1.1',
        ];
        $request->header = [
            'content-type' => 'application/json',
            'X-Custom-Token' => 'abc-123',
            'accept' => 'application/json, text/plain',
        ];

        $psrRequest = (new StoaRequestFactory())->create($request);

        self::assertSame('application/json', $psrRequest->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $psrRequest->getHeaderLine('CONTENT-TYPE'));
        self::assertSame('abc-123', $psrRequest->getHeaderLine('x-custom-token'));
        self::assertSame('application/json, text/plain', $psrRequest->getHeaderLine('accept'));
        self::assertTrue($psrRequest->hasHeader('X-Custom-Token'));
        self::assertTrue($psrRequest->hasHeader('x-custom-token'));
    }

    protected function setUp(): void
    {
        PlainTextStoaRoute::$disposed = false;
        FailingStoaRoute::$disposed = false;
    }

    /**
     * @param list<\Phalanx\Runtime\Memory\RuntimeLifecycleEvent> $events
     * @return list<string>
     */
    private static function eventTypesForResource(array $events, string $resourceId): array
    {
        $types = [];
        foreach ($events as $event) {
            if ($event->resourceId === $resourceId) {
                $types[] = $event->type;
            }
        }

        return $types;
    }

    /**
     * @template T
     * @param Closure(StoaRunner, Application): T $test
     * @return T
     */
    private function withStoaRunner(
        RouteGroup $routes,
        Closure $test,
        ?StoaServerConfig $config = null,
    ): mixed {
        return $this->scope->run(static function () use ($routes, $test, $config): mixed {
            $app = Application::starting()->compile()->startup();

            try {
                $runner = ($config === null ? StoaRunner::from($app) : StoaRunner::from($app, $config))
                    ->withRoutes($routes);

                return $test($runner, $app);
            } finally {
                $app->shutdown();
            }
        });
    }
}

final class PlainTextStoaRoute implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(RequestScope $scope): string
    {
        $scope->onDispose(static function (): void {
            self::$disposed = true;
        });

        return 'stoa-ok';
    }
}

final class JsonStoaRoute implements Scopeable
{
    /** @return array{path: string, name: string} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'path' => $scope->path(),
            'name' => (string) $scope->query->get('name'),
        ];
    }
}

final class HeadStoaRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): PsrResponse
    {
        return new PsrResponse(200, ['X-Head-Proof' => 'yes'], 'hidden body');
    }
}

final class NoContentStoaRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): PsrResponse
    {
        return new PsrResponse(204, [], 'hidden body');
    }
}

final class NotModifiedStoaRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): PsrResponse
    {
        return new PsrResponse(304, ['ETag' => '"demo"'], 'hidden body');
    }
}

final class ExistingPoweredByStoaRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): PsrResponse
    {
        return new PsrResponse(200, ['X-Powered-By' => 'Existing'], 'powered');
    }
}

final class ResourceAwareStoaRoute implements Scopeable
{
    /** @return array{resource_id: string, route: string, param: string} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'resource_id' => $scope->resourceId,
            'route' => $scope->runtime->memory->resources->annotation($scope->resourceId, StoaAnnotationSid::Route),
            'param' => $scope->params->required('id'),
        ];
    }
}

final class LongPathStoaRoute implements Scopeable
{
    /** @return array{path: string, path_annotation: string} */
    public function __invoke(RequestScope $scope): array
    {
        return [
            'path' => $scope->path(),
            'path_annotation' => $scope->runtime->memory->resources->annotation(
                $scope->resourceId,
                StoaAnnotationSid::Path,
            ),
        ];
    }
}

final class FailingStoaRoute implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(RequestScope $scope): never
    {
        $scope->onDispose(static function (): void {
            self::$disposed = true;
        });

        throw new RuntimeException('expected failure');
    }
}

final class ExplodingPathRequest extends ServerRequest
{
    public function __construct()
    {
        parent::__construct('GET', '/unavailable');
    }

    #[\Override]
    public function getUri(): UriInterface
    {
        throw new RuntimeException('request path unavailable');
    }
}
