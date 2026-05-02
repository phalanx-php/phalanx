<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use GuzzleHttp\Psr7\ServerRequest;
use OpenSwoole\Http\Request as OpenSwooleRequest;
use Phalanx\Application;
use Phalanx\Stoa\StoaRequestFactory;
use Phalanx\Stoa\StoaRunner;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoaRunnerTest extends TestCase
{
    protected function setUp(): void
    {
        PlainTextStoaRoute::$disposed = false;
        FailingStoaRoute::$disposed = false;
    }

    #[Test]
    public function dispatches_plaintext_route_and_disposes_scope(): void
    {
        $app = Application::starting()->compile()->startup();
        $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([
            'GET /plaintext' => PlainTextStoaRoute::class,
        ]));

        try {
            $response = $runner->dispatch(new ServerRequest('GET', '/plaintext'));
        } finally {
            $app->shutdown();
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('stoa-ok', (string) $response->getBody());
        self::assertTrue(PlainTextStoaRoute::$disposed);
        self::assertSame(0, $runner->activeRequests());
    }

    #[Test]
    public function dispatches_json_route_through_existing_route_scope(): void
    {
        $app = Application::starting()->compile()->startup();
        $runner = StoaRunner::from($app)->withRoutes(RouteGroup::of([
            'GET /json' => JsonStoaRoute::class,
        ]));

        try {
            $request = (new ServerRequest('GET', '/json?name=phalanx'))
                ->withQueryParams(['name' => 'phalanx']);
            $response = $runner->dispatch($request);
        } finally {
            $app->shutdown();
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['path' => '/json', 'name' => 'phalanx'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function disposes_scope_after_handler_exception(): void
    {
        $app = Application::starting()->compile()->startup();
        $runner = StoaRunner::from($app, debug: true)->withRoutes(RouteGroup::of([
            'GET /fail' => FailingStoaRoute::class,
        ]));

        try {
            $response = $runner->dispatch(new ServerRequest('GET', '/fail'));
        } finally {
            $app->shutdown();
        }

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Internal Server Error', $body['error']);
        self::assertSame('expected failure', $body['message']);
        self::assertSame('GET', $body['request']['method']);
        self::assertSame('/fail', $body['request']['path']);
        self::assertSame('failed', $body['request']['state']);
        self::assertIsString($body['tasks']);
        self::assertTrue(FailingStoaRoute::$disposed);
        self::assertSame(0, $runner->activeRequests());
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
