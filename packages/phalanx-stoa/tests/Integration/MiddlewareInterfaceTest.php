<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Closure;
use Phalanx\Application;
use Phalanx\Stoa\Contract\Middleware;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Verifies the typed Middleware interface dispatches correctly and composes in
 * the same order as the executable PrefixingMiddleware fixture.
 */
final class MiddlewareInterfaceTest extends TestCase
{
    private Application $app;

    #[Test]
    public function middleware_interface_wraps_result_in_order(): void
    {
        $group = RouteGroup::of([
            'GET /test' => PrefixingMiddlewareV2Handler::class,
        ])->wrap(PrefixingMiddlewareV2::class);

        $request = $this->createRequest('GET', '/test');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        $result = $scope->execute($group);

        // Same order as PrefixingMiddleware: outermost runs first and last
        $this->assertSame('before:ok:after', $result);
    }

    #[Test]
    public function middleware_interface_can_abort_chain(): void
    {
        $group = RouteGroup::of([
            'GET /test' => PrefixingMiddlewareV2Handler::class,
        ])->wrap(AbortingMiddlewareV2::class);

        $request = $this->createRequest('GET', '/test');
        $scope = $this->app->createScope()->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame('aborted', $result);
    }

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createStub(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }
}

/**
 * Handler fixture used by MiddlewareInterfaceTest -- returns 'ok'.
 */
final class PrefixingMiddlewareV2Handler implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        return 'ok';
    }
}

/**
 * Middleware implementing the typed Middleware interface. Wraps the inner
 * result with "before:" prefix and ":after" suffix. Composition order matches
 * the executable PrefixingMiddleware test fixture.
 */
final class PrefixingMiddlewareV2 implements Middleware
{
    public function __invoke(RequestScope $scope, Closure $next): mixed
    {
        $inner = $next($scope);
        return 'before:' . $inner . ':after';
    }
}

/**
 * Middleware that short-circuits the chain without calling $next.
 */
final class AbortingMiddlewareV2 implements Middleware
{
    public function __invoke(RequestScope $scope, Closure $next): mixed
    {
        return 'aborted';
    }
}
