<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Integration;

use Closure;
use Phalanx\Application;
use Phalanx\Http\Contract\Middleware;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteGroup;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use Phalanx\Testing\PhalanxTestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Verifies the typed Middleware interface dispatches correctly and composes in
 * the same order as the executable PrefixingMiddleware fixture.
 */
final class MiddlewareInterfaceTest extends PhalanxTestCase
{
    private Application $app;

    #[Test]
    public function middleware_interface_wraps_result_in_order(): void
    {
        $group = RouteGroup::of([
            'GET /test' => PrefixingMiddlewareV2Handler::class,
        ])->wrap(PrefixingMiddlewareV2::class);

        $request = $this->createRequest('GET', '/test');

        $result = $this->dispatch($group, $request);

        // Same order as PrefixingMiddleware: outermost runs first and last
        self::assertSame('before:ok:after', $result);
    }

    #[Test]
    public function middleware_interface_can_abort_chain(): void
    {
        $group = RouteGroup::of([
            'GET /test' => PrefixingMiddlewareV2Handler::class,
        ])->wrap(AbortingMiddlewareV2::class);

        $request = $this->createRequest('GET', '/test');

        $result = $this->dispatch($group, $request);

        self::assertSame('aborted', $result);
    }

    protected function setUp(): void
    {
        $this->app = $this->testApp()->application;
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

    private function dispatch(RouteGroup $group, ServerRequestInterface $request): mixed
    {
        return $group->dispatch($this->app->createScope(), $request);
    }
}

/**
 * Handler fixture used by MiddlewareInterfaceTest -- returns 'ok'.
 */
final class PrefixingMiddlewareV2Handler implements Scopeable
{
    public function __invoke(RequestContext $ctx): string
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
    public function __invoke(RequestContext $ctx, Closure $next): mixed
    {
        $inner = $next($ctx);
        return 'before:' . $inner . ':after';
    }
}

/**
 * Middleware that short-circuits the chain without calling $next.
 */
final class AbortingMiddlewareV2 implements Middleware
{
    public function __invoke(RequestContext $ctx, Closure $next): mixed
    {
        return 'aborted';
    }
}
