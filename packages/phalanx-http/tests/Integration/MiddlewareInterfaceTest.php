<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration;

use Closure;
use Phalanx\Application;
use Phalanx\ExecutionScope;
use Phalanx\Http\Contract\Middleware;
use Phalanx\Http\RequestScope;
use Phalanx\Http\RouteGroup;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Verifies the typed Middleware interface dispatches correctly and composes in
 * the same order as the legacy Executable-based PrefixingMiddleware fixture.
 */
final class MiddlewareInterfaceTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        $this->app = Application::starting()->compile();
    }

    protected function tearDown(): void
    {
        $this->app->shutdown();
    }

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

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
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
 * the legacy PrefixingMiddleware test fixture.
 */
final class PrefixingMiddlewareV2 implements Middleware, Executable
{
    public function handle(RequestScope $scope, Closure $next): mixed
    {
        $inner = $next($scope);
        return 'before:' . $inner . ':after';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        assert($scope instanceof RequestScope);

        /** @var Scopeable|Executable $next */
        $next = $scope->attribute('handler.next');

        return $this->handle(
            $scope,
            static function (RequestScope $s) use ($next): mixed {
                return $next($s);
            },
        );
    }
}

/**
 * Middleware that short-circuits the chain without calling $next.
 */
final class AbortingMiddlewareV2 implements Middleware, Executable
{
    public function handle(RequestScope $scope, Closure $next): mixed
    {
        return 'aborted';
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        assert($scope instanceof RequestScope);

        return $this->handle(
            $scope,
            static fn(RequestScope $s): mixed => null,
        );
    }
}
