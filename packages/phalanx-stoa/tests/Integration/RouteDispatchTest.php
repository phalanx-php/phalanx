<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use Phalanx\Application;
use Phalanx\Stoa\MethodNotAllowedException;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\RouteNotFoundException;
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
    public function fast_route_aliases_constrain_route_params(): void
    {
        $group = RouteGroup::of([
            'GET /users/{id:int}' => ShowRouteId::class,
        ]);

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/users/42'));

        self::assertSame('42', $scope->execute($group));

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/users/int'));

        $this->expectException(RouteNotFoundException::class);

        $scope->execute($group);
    }

    #[Test]
    public function fast_route_aliases_use_default_pattern_set(): void
    {
        $group = RouteGroup::of([
            'GET /posts/{id:slug}' => ShowRouteId::class,
        ]);

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/posts/hello-world'));

        self::assertSame('hello-world', $scope->execute($group));

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/posts/HelloWorld'));

        $this->expectException(RouteNotFoundException::class);

        $scope->execute($group);
    }

    #[Test]
    public function with_patterns_recompiles_existing_fast_route_paths(): void
    {
        $group = RouteGroup::of([
            'GET /codes/{id:code}' => ShowRouteId::class,
        ])->withPatterns(['code' => '[A-Z]+']);

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/codes/ABC'));

        self::assertSame('ABC', $scope->execute($group));

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/codes/abc'));

        $this->expectException(RouteNotFoundException::class);

        $scope->execute($group);
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
    public function throws_method_not_allowed_with_fast_route_allowed_methods(): void
    {
        $group = RouteGroup::of([
            'GET,POST /resource' => StatusOk::class,
        ]);

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('DELETE', '/resource'));

        try {
            $scope->execute($group);
            $this->fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(['GET', 'POST'], $e->allowedMethods);
        }
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
    public function mount_preserves_wrapped_group_middleware_without_leaking_to_siblings(): void
    {
        $public = RouteGroup::of([
            'GET /public' => StatusOk::class,
        ]);
        $admin = RouteGroup::of([
            'GET /admin' => StatusOk::class,
        ])->wrap(PrefixingMiddleware::class);

        $mounted = RouteGroup::of([])
            ->mount('/api', $public)
            ->mount('/api', $admin);

        $publicScope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/api/public'));
        $adminScope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/api/admin'));

        self::assertSame('ok', $publicScope->execute($mounted));
        self::assertSame('before:ok:after', $adminScope->execute($mounted));
    }

    #[Test]
    public function mount_preserves_fast_route_alias_constraints(): void
    {
        $group = RouteGroup::of([
            'GET /users/{id:int}' => ShowRouteId::class,
        ]);

        $mounted = RouteGroup::of([])->mount('/api/v1', $group);

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/api/v1/users/42'));

        self::assertSame('42', $scope->execute($mounted));

        $scope = $this->app->createScope()
            ->withAttribute('request', $this->createRequest('GET', '/api/v1/users/int'));

        $this->expectException(RouteNotFoundException::class);

        $scope->execute($mounted);
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
        $this->assertSame('/users/{id}', $handler->config->fastRoutePath);
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
