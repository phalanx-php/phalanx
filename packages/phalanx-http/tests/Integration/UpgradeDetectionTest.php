<?php

declare(strict_types=1);

namespace Phalanx\Tests\Http\Integration;

use Phalanx\Application;
use Phalanx\Handler\Handler;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteMatcher;
use Phalanx\Tests\Http\Fixtures\Routes\StatusOk;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

final class UpgradeDetectionTest extends TestCase
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
    public function http_request_skips_ws_routes(): void
    {
        $httpRoute = new Handler(
            StatusOk::class,
            RouteConfig::compile('/test', 'GET', 'http'),
        );

        $wsRoute = new Handler(
            StatusOk::class,
            RouteConfig::compile('/test', 'GET', 'ws'),
        );

        $request = $this->createRequest('GET', '/test');
        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $matcher = new RouteMatcher();
        $match = $matcher->match($scope, ['http' => $httpRoute, 'ws' => $wsRoute]);

        $this->assertNotNull($match);
        $this->assertSame($httpRoute, $match->handler);
    }

    #[Test]
    public function ws_upgrade_skips_http_routes(): void
    {
        $httpRoute = new Handler(
            StatusOk::class,
            RouteConfig::compile('/test', 'GET', 'http'),
        );

        $wsRoute = new Handler(
            StatusOk::class,
            RouteConfig::compile('/test', 'GET', 'ws'),
        );

        $request = $this->createWsUpgradeRequest('GET', '/test');
        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $matcher = new RouteMatcher();
        $match = $matcher->match($scope, ['http' => $httpRoute, 'ws' => $wsRoute]);

        $this->assertNotNull($match);
        $this->assertSame($wsRoute, $match->handler);
    }

    #[Test]
    public function ws_upgrade_to_unknown_path_throws(): void
    {
        $httpRoute = new Handler(
            StatusOk::class,
            RouteConfig::compile('/api', 'GET', 'http'),
        );

        $request = $this->createWsUpgradeRequest('GET', '/ws/chat');
        $scope = $this->app->createScope();
        $scope = $scope->withAttribute('request', $request);

        $matcher = new RouteMatcher();

        $this->expectException(\Phalanx\Http\RouteNotFoundException::class);
        $this->expectExceptionMessage('No route matches');

        $matcher->match($scope, ['http' => $httpRoute]);
    }

    #[Test]
    public function route_config_preserves_protocol_through_compile(): void
    {
        $config = RouteConfig::compile('/chat/{room}', 'GET', 'ws');

        $this->assertSame('ws', $config->protocol);
        $this->assertSame(['GET'], $config->methods);
        $this->assertSame(['room'], $config->paramNames);
    }

    #[Test]
    public function route_config_with_protocol_builder(): void
    {
        $config = RouteConfig::compile('/test', 'GET');
        $this->assertSame('http', $config->protocol);

        $wsConfig = $config->withProtocol('ws');
        $this->assertSame('ws', $wsConfig->protocol);
        $this->assertSame('http', $config->protocol);
    }

    #[Test]
    public function http_and_ws_routes_coexist_on_same_path(): void
    {
        $httpRoute = new Handler(
            StatusOk::class,
            RouteConfig::compile('/api', 'GET', 'http'),
        );

        $wsRoute = new Handler(
            StatusOk::class,
            RouteConfig::compile('/api', 'GET', 'ws'),
        );

        $handlers = ['http' => $httpRoute, 'ws' => $wsRoute];
        $matcher = new RouteMatcher();

        $httpReq = $this->createRequest('GET', '/api');
        $httpScope = $this->app->createScope()->withAttribute('request', $httpReq);
        $httpMatch = $matcher->match($httpScope, $handlers);

        $wsReq = $this->createWsUpgradeRequest('GET', '/api');
        $wsScope = $this->app->createScope()->withAttribute('request', $wsReq);
        $wsMatch = $matcher->match($wsScope, $handlers);

        $this->assertNotNull($httpMatch);
        $this->assertNotNull($wsMatch);
        $this->assertSame($httpRoute, $httpMatch->handler);
        $this->assertSame($wsRoute, $wsMatch->handler);
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getHeaderLine')->willReturn('');

        return $request;
    }

    private function createWsUpgradeRequest(string $method, string $path): ServerRequestInterface
    {
        $uri = $this->createMock(UriInterface::class);
        $uri->method('getPath')->willReturn($path);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getUri')->willReturn($uri);
        $request->method('getQueryParams')->willReturn([]);
        $request->method('getHeaderLine')->willReturnCallback(
            static fn(string $name): string => match (strtolower($name)) {
                'upgrade' => 'websocket',
                'connection' => 'Upgrade',
                default => '',
            },
        );

        return $request;
    }
}
