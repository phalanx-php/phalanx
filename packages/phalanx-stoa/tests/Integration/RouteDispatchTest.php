<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Phalanx\Application;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Tests\Fixtures\Handlers\PrefixingMiddleware;
use Phalanx\Tests\Stoa\Fixtures\Routes\ListPosts;
use Phalanx\Tests\Stoa\Fixtures\Routes\ListUsers;
use Phalanx\Tests\Stoa\Fixtures\Routes\ShowRouteId;
use Phalanx\Tests\Stoa\Fixtures\Routes\ShowUserById;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusList;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusOk;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusPosts;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusShow;
use Phalanx\Tests\Stoa\Fixtures\Routes\StatusUsers;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class RouteDispatchTest extends TestCase
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
    public function dispatches_route_by_request_attribute(): void
    {
        $group = RouteGroup::of([
            'GET /users' => ListUsers::class,
            'GET /posts' => ListPosts::class,
        ]);

        $request = $this->createRequest('GET', '/users');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame(['users' => []], $result);
    }

    #[Test]
    public function extracts_route_params_to_attributes(): void
    {
        $group = RouteGroup::of([
            'GET /users/{id}' => ShowUserById::class,
        ]);

        $request = $this->createRequest('GET', '/users/42');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame('42', $result['id']);
        $this->assertSame(['id' => '42'], $result['params']);
    }

    #[Test]
    public function throws_when_no_route_matches(): void
    {
        $group = RouteGroup::of([
            'GET /users' => ListUsers::class,
        ]);

        $request = $this->createRequest('GET', '/posts');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $this->expectException(\Phalanx\Stoa\RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matches GET /posts');

        $scope->execute($group);
    }

    #[Test]
    public function applies_group_middleware(): void
    {
        $group = RouteGroup::of([
            'GET /test' => StatusOk::class,
        ])->wrap(PrefixingMiddleware::class);

        $request = $this->createRequest('GET', '/test');

        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($group);

        $this->assertSame('before:ok:after', $result);
    }

    #[Test]
    public function matches_multiple_methods(): void
    {
        $group = RouteGroup::of([
            'GET,POST /resource' => StatusOk::class,
        ]);

        foreach (['GET', 'POST'] as $method) {
            $request = $this->createRequest($method, '/resource');
            $scope = $this->app->createScope();
            $scope = $scope->withAttribute('request', $request);

            $result = $scope->execute($group);

            $this->assertSame('ok', $result);
        }
    }

    #[Test]
    public function mount_prefixes_routes(): void
    {
        $group = RouteGroup::of([
            'GET /users' => StatusList::class,
            'GET /users/{id}' => ShowRouteId::class,
        ]);

        $mounted = RouteGroup::of([])->mount('/api/v1', $group);

        $this->assertContains('GET /api/v1/users', $mounted->keys());
        $this->assertContains('GET /api/v1/users/{id}', $mounted->keys());

        $request = $this->createRequest('GET', '/api/v1/users/42');
        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $result = $scope->execute($mounted);

        $this->assertSame('42', $result);
    }

    #[Test]
    public function route_group_keys_and_merge(): void
    {
        $group1 = RouteGroup::of([
            'GET /users' => StatusUsers::class,
        ]);

        $group2 = RouteGroup::of([
            'GET /posts' => StatusPosts::class,
        ]);

        $merged = $group1->merge($group2);

        $this->assertCount(2, $merged->keys());
        $this->assertContains('GET /users', $merged->keys());
        $this->assertContains('GET /posts', $merged->keys());
    }

    #[Test]
    public function compiles_route_pattern_from_key(): void
    {
        $group = RouteGroup::of([
            'GET /users/{id}' => StatusShow::class,
        ]);

        $handler = $group->handlers()->get('GET /users/{id}');

        $this->assertNotNull($handler);
        $this->assertInstanceOf(RouteConfig::class, $handler->config);
        $this->assertSame(['id'], $handler->config->paramNames);
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
