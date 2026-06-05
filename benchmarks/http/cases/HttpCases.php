<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Http\Cases;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Benchmarks\Http\AbstractHttpBenchmarkCase;
use Phalanx\Benchmarks\Kit\BenchmarkApp;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\HttpRequestFactory;
use Phalanx\Http\HttpRunner;
use Phalanx\Http\HttpServerConfig;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Http\Request as SwooleRequest;

final class HttpDispatchPlaintextCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('http_dispatch_plaintext', 5_000, 100);
    }

    public function run(BenchmarkApp $app): void
    {
        $response = $app->httpRunner('plaintext', RouteGroup::of([
            'GET /plaintext' => BenchmarkPlaintextRoute::class,
        ]))->dispatch(new ServerRequest('GET', '/plaintext'));

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Plaintext benchmark route returned an unexpected status.');
        }
    }
}

final class HttpDispatchJsonCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('http_dispatch_json', 5_000, 100);
    }

    public function run(BenchmarkApp $app): void
    {
        $response = $app->httpRunner('json', RouteGroup::of([
            'GET /json' => BenchmarkJsonRoute::class,
        ]))->dispatch(new ServerRequest('GET', '/json'));

        if ($response->getHeaderLine('Content-Type') !== 'application/json') {
            throw new RuntimeException('JSON benchmark route returned an unexpected content type.');
        }
    }
}

final class HttpDispatchRouteParamCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('http_dispatch_route_param', 5_000, 100);
    }

    public function run(BenchmarkApp $app): void
    {
        $response = $app->httpRunner('route-param', RouteGroup::of([
            'GET /users/{id:int}' => BenchmarkRouteParamRoute::class,
        ]))->dispatch(new ServerRequest('GET', '/users/42'));

        if ((string) $response->getBody() !== '42') {
            throw new RuntimeException('Route parameter benchmark returned an unexpected body.');
        }
    }
}

final class HttpRequestFactoryCase extends AbstractHttpBenchmarkCase
{
    private HttpRequestFactory $factory;

    private SwooleRequest $request;

    public function __construct()
    {
        parent::__construct('http_request_factory', 10_000, 100);
        $this->factory = new HttpRequestFactory();
        $this->request = self::request();
    }

    public function run(BenchmarkApp $app): void
    {
        $request = $this->factory->create($this->request);

        if ($request->getUri()->getPath() !== '/submit') {
            throw new RuntimeException('Request factory benchmark produced an unexpected path.');
        }
    }

    private static function request(): SwooleRequest
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
        $request->cookie = ['sid' => 'bench'];
        $request->post = ['name' => 'Ada'];

        return $request;
    }
}

final class HttpRequestResourceLifecycleCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('http_request_resource_lifecycle', 2_000, 50);
    }

    public function run(BenchmarkApp $app): void
    {
        $runner = $app->httpRunner('resource-lifecycle', RouteGroup::of([
            'GET /resource' => BenchmarkResourceRoute::class,
        ]));

        $response = $runner->dispatch(new ServerRequest('GET', '/resource'));

        if ($response->getStatusCode() !== 200 || $runner->activeRequests() !== 0) {
            throw new RuntimeException('Request resource benchmark did not clean up the active request.');
        }
    }
}

final class HttpDrainCleanupCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('http_drain_cleanup', 20, 2);
    }

    public function run(BenchmarkApp $app): void
    {
        $internalApp = Application::starting()->compile()->startup();
        $runner = HttpRunner::from($internalApp, new HttpServerConfig(requestTimeout: 1.0, drainTimeout: 0.01))
            ->withRoutes(RouteGroup::of([
                'GET /drain' => BenchmarkDrainRoute::class,
            ]));
        $responses = new Channel(1);

        try {
            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/drain')));
            });

            Coroutine::sleep(0.001);
            $runner->stop();
            $response = $responses->pop(1.0);

            if (!$response instanceof ResponseInterface || $runner->activeRequests() !== 0) {
                throw new RuntimeException('Drain benchmark did not produce a cleaned response.');
            }
        } finally {
            $responses->close();
            $internalApp->shutdown();
        }
    }
}

final class BenchmarkPlaintextRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!');
    }
}

final class BenchmarkJsonRoute implements Scopeable
{
    /** @return array{message: string} */
    public function __invoke(RequestContext $ctx): array
    {
        return ['message' => 'Hello, World!'];
    }
}

final class BenchmarkRouteParamRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): string
    {
        return $ctx->params->required('id');
    }
}

final class BenchmarkResourceRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): Response
    {
        if (!str_starts_with($ctx->requestId, 'http-request-')) {
            throw new RuntimeException('Request resource id was not exposed to the benchmark route.');
        }

        return new Response(200, ['Content-Type' => 'text/plain'], $ctx->requestId);
    }
}

final class BenchmarkDrainRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): string
    {
        $ctx->delay(0.2);

        return 'completed';
    }
}
