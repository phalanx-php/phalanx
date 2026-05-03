<?php

declare(strict_types=1);

namespace Phalanx\Benchmarks\Http\Cases;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use OpenSwoole\Http\Request as OpenSwooleRequest;
use Phalanx\Application;
use Phalanx\Benchmarks\Http\AbstractHttpBenchmarkCase;
use Phalanx\Benchmarks\Http\HttpBenchmarkContext;
use Phalanx\Benchmarks\Http\HttpBenchmarkSuite;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaRequestFactory;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class StoaDispatchPlaintextCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('stoa_dispatch_plaintext', 5_000, 100);
    }

    public function run(HttpBenchmarkContext $context): void
    {
        $response = $context->runner('plaintext', RouteGroup::of([
            'GET /plaintext' => BenchmarkPlaintextRoute::class,
        ]))->dispatch(new ServerRequest('GET', '/plaintext'));

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Plaintext benchmark route returned an unexpected status.');
        }
    }
}

final class StoaDispatchJsonCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('stoa_dispatch_json', 5_000, 100);
    }

    public function run(HttpBenchmarkContext $context): void
    {
        $response = $context->runner('json', RouteGroup::of([
            'GET /json' => BenchmarkJsonRoute::class,
        ]))->dispatch(new ServerRequest('GET', '/json'));

        if ($response->getHeaderLine('Content-Type') !== 'application/json') {
            throw new RuntimeException('JSON benchmark route returned an unexpected content type.');
        }
    }
}

final class StoaDispatchRouteParamCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('stoa_dispatch_route_param', 5_000, 100);
    }

    public function run(HttpBenchmarkContext $context): void
    {
        $response = $context->runner('route-param', RouteGroup::of([
            'GET /users/{id:int}' => BenchmarkRouteParamRoute::class,
        ]))->dispatch(new ServerRequest('GET', '/users/42'));

        if ((string) $response->getBody() !== '42') {
            throw new RuntimeException('Route parameter benchmark returned an unexpected body.');
        }
    }
}

final class StoaRequestFactoryCase extends AbstractHttpBenchmarkCase
{
    private StoaRequestFactory $factory;

    private OpenSwooleRequest $request;

    public function __construct()
    {
        parent::__construct('stoa_request_factory', 10_000, 100);
        $this->factory = new StoaRequestFactory();
        $this->request = self::request();
    }

    private static function request(): OpenSwooleRequest
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
        $request->cookie = ['sid' => 'bench'];
        $request->post = ['name' => 'Ada'];

        return $request;
    }

    public function run(HttpBenchmarkContext $context): void
    {
        $request = $this->factory->create($this->request);

        if ($request->getUri()->getPath() !== '/submit') {
            throw new RuntimeException('Request factory benchmark produced an unexpected path.');
        }
    }
}

final class StoaRequestResourceLifecycleCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('stoa_request_resource_lifecycle', 2_000, 50);
    }

    public function run(HttpBenchmarkContext $context): void
    {
        $runner = $context->runner('resource-lifecycle', RouteGroup::of([
            'GET /resource' => BenchmarkResourceRoute::class,
        ]));

        $response = $runner->dispatch(new ServerRequest('GET', '/resource'));

        if ($response->getStatusCode() !== 200 || $runner->activeRequests() !== 0) {
            throw new RuntimeException('Request resource benchmark did not clean up the active request.');
        }
    }
}

final class StoaDrainCleanupCase extends AbstractHttpBenchmarkCase
{
    public function __construct()
    {
        parent::__construct('stoa_drain_cleanup', 20, 2);
    }

    public function run(HttpBenchmarkContext $context): void
    {
        $app = Application::starting()->compile()->startup();
        $runner = StoaRunner::from($app, new StoaServerConfig(requestTimeout: 1.0, drainTimeout: 0.01))
            ->withRoutes(RouteGroup::of([
                'GET /drain' => BenchmarkDrainRoute::class,
            ]));
        $responses = new Channel(1);

        try {
            Coroutine::create(static function () use ($runner, $responses): void {
                $responses->push($runner->dispatch(new ServerRequest('GET', '/drain')));
            });

            Coroutine::usleep(1_000);
            $runner->stop();
            $response = $responses->pop(1.0);

            if (!$response instanceof ResponseInterface || $runner->activeRequests() !== 0) {
                throw new RuntimeException('Drain benchmark did not produce a cleaned response.');
            }
        } finally {
            $responses->close();
            $app->shutdown();
        }
    }
}

final class BenchmarkPlaintextRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): Response
    {
        return new Response(200, ['Content-Type' => 'text/plain'], 'Hello, World!');
    }
}

final class BenchmarkJsonRoute implements Scopeable
{
    /** @return array{message: string} */
    public function __invoke(RequestScope $scope): array
    {
        return ['message' => 'Hello, World!'];
    }
}

final class BenchmarkRouteParamRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        return $scope->params->required('id');
    }
}

final class BenchmarkResourceRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): Response
    {
        if (!str_starts_with($scope->resourceId, 'stoa-request-')) {
            throw new RuntimeException('Request resource id was not exposed to the benchmark route.');
        }

        return new Response(200, ['Content-Type' => 'text/plain'], $scope->resourceId);
    }
}

final class BenchmarkDrainRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        $scope->delay(0.2);

        return 'completed';
    }
}

function stoaHttpCases(): HttpBenchmarkSuite
{
    return HttpBenchmarkSuite::of(
        new StoaDispatchPlaintextCase(),
        new StoaDispatchJsonCase(),
        new StoaDispatchRouteParamCase(),
        new StoaRequestFactoryCase(),
        new StoaRequestResourceLifecycleCase(),
        new StoaDrainCleanupCase(),
    );
}
