<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Integration;

use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\ServerRequest;
use Swoole\Http\Request as SwooleRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Runtime\Identity\RuntimeAnnotationId;
use Phalanx\Runtime\Identity\RuntimeEventId;
use Phalanx\Runtime\Identity\RuntimeResourceId;
use Phalanx\Runtime\Memory\RuntimeMemory;
use Phalanx\Runtime\Memory\RuntimeMemoryConfig;
use Phalanx\Runtime\RuntimeContext;
use Phalanx\Http\ExecutionContext;
use Phalanx\Http\MissingRequestResource;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\RouteParams;
use Phalanx\Http\Runtime\Identity\HttpAnnotationSid;
use Phalanx\Http\Runtime\Identity\HttpEventSid;
use Phalanx\Http\Runtime\Identity\HttpResourceSid;
use Phalanx\Http\HttpRequestFactory;
use Phalanx\Http\HttpRequestResource;
use Phalanx\Http\HttpRunner;
use Phalanx\Http\HttpServerConfig;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class HttpRunnerTest extends PhalanxTestCase
{
    #[Test]
    public function http_runtime_identities_are_typed_and_stable(): void
    {
        self::assertSame('HttpRequest', HttpResourceSid::HttpRequest->key());
        self::assertSame('http.http_request', HttpResourceSid::HttpRequest->value());
        self::assertSame('Route', HttpAnnotationSid::Route->key());
        self::assertSame('http.route', HttpAnnotationSid::Route->value());
        self::assertSame('RouteMatched', HttpEventSid::RouteMatched->key());
        self::assertSame('http.route_matched', HttpEventSid::RouteMatched->value());
        self::assertInstanceOf(RuntimeResourceId::class, HttpResourceSid::HttpRequest);
        self::assertInstanceOf(RuntimeAnnotationId::class, HttpAnnotationSid::Route);
        self::assertInstanceOf(RuntimeEventId::class, HttpEventSid::RouteMatched);
    }

    #[Test]
    public function dispatches_plaintext_route_and_disposes_scope(): void
    {
        [$response, $activeRequests] = $this->withHttpRunner(RouteGroup::of([
            'GET /plaintext' => PlainTextHttpRoute::class,
        ]), static function (HttpRunner $runner): array {
            $response = $runner->dispatch(new ServerRequest('GET', '/plaintext'));

            return [$response, $runner->activeRequests()];
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('http-ok', (string) $response->getBody());
        self::assertTrue(PlainTextHttpRoute::$disposed);
        self::assertSame(0, $activeRequests);
    }

    #[Test]
    public function head_request_uses_get_route_without_response_body(): void
    {
        $response = $this->withHttpRunner(RouteGroup::of([
            'GET /head' => HeadHttpRoute::class,
        ]), static fn(HttpRunner $runner) => $runner->dispatch(new ServerRequest('HEAD', '/head')));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Head-Proof'));
        self::assertSame('', (string) $response->getBody());
    }

    #[Test]
    public function no_content_and_not_modified_responses_do_not_expose_bodies(): void
    {
        [$empty, $cached] = $this->withHttpRunner(RouteGroup::of([
            'GET /empty' => NoContentHttpRoute::class,
            'GET /cached' => NotModifiedHttpRoute::class,
        ]), static function (HttpRunner $runner): array {
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
        $default = $this->withHttpRunner(RouteGroup::of([
            'GET /plaintext' => PlainTextHttpRoute::class,
        ]), static fn(HttpRunner $runner) => $runner->dispatch(new ServerRequest('GET', '/plaintext')));

        $custom = $this->withHttpRunner(RouteGroup::of([
            'GET /powered' => ExistingPoweredByHttpRoute::class,
        ]), static fn(HttpRunner $runner) => $runner->dispatch(new ServerRequest('GET', '/powered')));

        $disabled = $this->withHttpRunner(
            RouteGroup::of([
                'GET /plaintext' => PlainTextHttpRoute::class,
            ]),
            static fn(HttpRunner $runner) => $runner->dispatch(new ServerRequest('GET', '/plaintext')),
            new HttpServerConfig(poweredBy: null),
        );

        self::assertSame('Phalanx', $default->getHeaderLine('X-Powered-By'));
        self::assertSame('Existing', $custom->getHeaderLine('X-Powered-By'));
        self::assertFalse($disabled->hasHeader('X-Powered-By'));
    }

    #[Test]
    public function dispatches_json_route_through_existing_route_scope(): void
    {
        $response = $this->withHttpRunner(RouteGroup::of([
            'GET /json' => JsonHttpRoute::class,
        ]), static function (HttpRunner $runner) {
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
    public function request_scope_exposes_owned_request_id(): void
    {
        [$response, $body, $resourceEvents, $released] = $this->withHttpRunner(RouteGroup::of([
            'GET /resource/{id:int}' => ResourceAwareHttpRoute::class,
        ]), static function (HttpRunner $runner, Application $app): array {
            $response = $runner->dispatch(new ServerRequest('GET', '/resource/42'));
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $events = $app->runtime()->memory->events->recent();
            $resourceEvents = self::eventTypesForResource($events, (string) $body['request_id']);
            $released = $app->runtime()->memory->resources->get((string) $body['request_id']) === null;

            return [$response, $body, $resourceEvents, $released];
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($body['request_id']);
        self::assertStringStartsWith('http-request-', $body['request_id']);
        self::assertSame('/resource/{id:int}', $body['route']);
        self::assertSame('42', $body['param']);
        self::assertTrue($released);
        self::assertContains('resource.opened', $resourceEvents);
        self::assertContains(HttpEventSid::RouteMatched->value(), $resourceEvents);
        self::assertContains('resource.closed', $resourceEvents);
        self::assertContains('resource.released', $resourceEvents);
    }

    #[Test]
    public function long_request_path_is_bounded_in_runtime_annotations(): void
    {
        $path = '/long/' . str_repeat('x', 300);

        [$response, $body, $activeRequests, $liveRequests] = $this->withHttpRunner(RouteGroup::of([
            'GET /long/{slug}' => LongPathHttpRoute::class,
        ]), static function (HttpRunner $runner, Application $app) use ($path): array {
            $response = $runner->dispatch(new ServerRequest('GET', $path));
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $activeRequests = $runner->activeRequests();
            $liveRequests = $app->runtime()->memory->resources->liveCount(HttpResourceSid::HttpRequest);

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
        [$caught, $activeRequests, $liveResources] = $this->withHttpRunner(RouteGroup::of([
            'GET /plaintext' => PlainTextHttpRoute::class,
        ]), static function (HttpRunner $runner, Application $app): array {
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
                HttpRequestResource::open($runtime, $request, $token);
            } catch (RuntimeException $e) {
                $caught = $e;
            }

            self::assertInstanceOf(RuntimeException::class, $caught);
            self::assertSame(0, $memory->resources->liveCount(HttpResourceSid::HttpRequest));
        } finally {
            $token->cancel();
            $memory->shutdown();
        }
    }

    #[Test]
    public function request_scope_request_id_fails_loud_when_missing(): void
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
                self::assertSame('', $context->requestId);
            } finally {
                $scope->dispose();
                $app->shutdown();
            }
        });
    }

    #[Test]
    public function disposes_scope_after_handler_exception(): void
    {
        [$response, $events] = $this->withHttpRunner(
            RouteGroup::of([
                'GET /fail' => FailingHttpRoute::class,
            ]),
            static function (HttpRunner $runner, Application $app): array {
                $response = $runner->dispatch(new ServerRequest('GET', '/fail'));
                $events = $app->runtime()->memory->events->recent();

                return [$response, $events];
            },
            new HttpServerConfig(ignitionEnabled: true),
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
            HttpEventSid::RequestFailed->value(),
            self::eventTypesForResource($events, (string) $body['request']['id']),
        );
        self::assertContains(
            'resource.released',
            self::eventTypesForResource($events, (string) $body['request']['id']),
        );
        self::assertTrue(FailingHttpRoute::$disposed);
    }

    #[Test]
    public function translates_swoole_request_to_psr_request(): void
    {
        $request = new SwooleRequest();
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

        $psrRequest = (new HttpRequestFactory())->create($request);

        self::assertSame('POST', $psrRequest->getMethod());
        self::assertSame('/submit', $psrRequest->getUri()->getPath());
        self::assertSame(['page' => '2'], $psrRequest->getQueryParams());
        self::assertSame(['sid' => 'abc'], $psrRequest->getCookieParams());
        self::assertSame(['name' => 'Ada'], $psrRequest->getParsedBody());
        self::assertSame('application/json', $psrRequest->getHeaderLine('content-type'));
    }

    #[Test]
    public function translates_uploaded_files_from_swoole_request(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'http-upload-');
        self::assertIsString($tmpFile);
        file_put_contents($tmpFile, 'upload-body');

        $request = new SwooleRequest();
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
            $psrRequest = (new HttpRequestFactory())->create($request);
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
    public function translates_indexed_uploaded_file_list_from_swoole_request(): void
    {
        $first = tempnam(sys_get_temp_dir(), 'http-upload-a-');
        $second = tempnam(sys_get_temp_dir(), 'http-upload-b-');
        self::assertIsString($first);
        self::assertIsString($second);
        file_put_contents($first, 'first-body');
        file_put_contents($second, 'second-body');

        $request = new SwooleRequest();
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
            $psrRequest = (new HttpRequestFactory())->create($request);
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
        $present = tempnam(sys_get_temp_dir(), 'http-upload-c-');
        self::assertIsString($present);
        file_put_contents($present, 'only-real');

        $request = new SwooleRequest();
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
            $psrRequest = (new HttpRequestFactory())->create($request);
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
    public function preserves_psr_header_lookups_regardless_of_swoole_header_case(): void
    {
        $request = new SwooleRequest();
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

        $psrRequest = (new HttpRequestFactory())->create($request);

        self::assertSame('application/json', $psrRequest->getHeaderLine('Content-Type'));
        self::assertSame('application/json', $psrRequest->getHeaderLine('CONTENT-TYPE'));
        self::assertSame('abc-123', $psrRequest->getHeaderLine('x-custom-token'));
        self::assertSame('application/json, text/plain', $psrRequest->getHeaderLine('accept'));
        self::assertTrue($psrRequest->hasHeader('X-Custom-Token'));
        self::assertTrue($psrRequest->hasHeader('x-custom-token'));
    }

    protected function setUp(): void
    {
        PlainTextHttpRoute::$disposed = false;
        FailingHttpRoute::$disposed = false;
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
     * @param Closure(HttpRunner, Application): T $test
     * @return T
     */
    private function withHttpRunner(
        RouteGroup $routes,
        Closure $test,
        ?HttpServerConfig $config = null,
    ): mixed {
        return $this->scope->run(static function () use ($routes, $test, $config): mixed {
            $app = Application::starting()->compile()->startup();

            try {
                $runner = ($config === null ? HttpRunner::from($app) : HttpRunner::from($app, $config))
                    ->withRoutes($routes);

                return $test($runner, $app);
            } finally {
                $app->shutdown();
            }
        });
    }
}

final class PlainTextHttpRoute implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(RequestContext $ctx): string
    {
        $ctx->onDispose(static function (): void {
            self::$disposed = true;
        });

        return 'http-ok';
    }
}

final class JsonHttpRoute implements Scopeable
{
    /** @return array{path: string, name: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'path' => $ctx->path(),
            'name' => (string) $ctx->query->get('name'),
        ];
    }
}

final class HeadHttpRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): PsrResponse
    {
        return new PsrResponse(200, ['X-Head-Proof' => 'yes'], 'hidden body');
    }
}

final class NoContentHttpRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): PsrResponse
    {
        return new PsrResponse(204, [], 'hidden body');
    }
}

final class NotModifiedHttpRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): PsrResponse
    {
        return new PsrResponse(304, ['ETag' => '"demo"'], 'hidden body');
    }
}

final class ExistingPoweredByHttpRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): PsrResponse
    {
        return new PsrResponse(200, ['X-Powered-By' => 'Existing'], 'powered');
    }
}

final class ResourceAwareHttpRoute implements Scopeable
{
    /** @return array{request_id: string, route: string, param: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'request_id' => $ctx->requestId,
            'route' => $ctx->runtime->memory->resources->annotation($ctx->requestId, HttpAnnotationSid::Route),
            'param' => $ctx->params->required('id'),
        ];
    }
}

final class LongPathHttpRoute implements Scopeable
{
    /** @return array{path: string, path_annotation: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return [
            'path' => $ctx->path(),
            'path_annotation' => $ctx->runtime->memory->resources->annotation(
                $ctx->requestId,
                HttpAnnotationSid::Path,
            ),
        ];
    }
}

final class FailingHttpRoute implements Scopeable
{
    public static bool $disposed = false;

    public function __invoke(RequestContext $ctx): never
    {
        $ctx->onDispose(static function (): void {
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
