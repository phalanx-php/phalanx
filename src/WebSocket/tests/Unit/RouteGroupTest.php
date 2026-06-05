<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\WebSocket\Gateway;
use Phalanx\WebSocket\RouteGroup;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Http\ExecutionContext as HttpExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteMatcher;
use Phalanx\Http\RouteParams;
use Phalanx\Http\Runtime\Identity\HttpAnnotationSid;
use Phalanx\Http\RequestDiagnostics;
use Phalanx\Http\RequestResource;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WsRouteGroupTest extends TestCase
{
    #[Test]
    public function websocket_routes_match_against_typed_request_scope_and_expose_params(): void
    {
        $app = Application::starting()->compile()->startup();
        $scope = $app->createScope();
        self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);

        $request = new ServerRequest('GET', '/socket/42');
        $token = CancellationToken::create();
        $resource = \Phalanx\Http\RequestResource::open($app->runtime(), $request, $token, ownerScopeId: $scope->scopeId);
        $scope->bindScopedInstance(\Phalanx\Http\RequestResource::class, $resource, inherit: true);
        $scope->bindScopedInstance(\Phalanx\Http\RequestDiagnostics::class, new \Phalanx\Http\RequestDiagnostics(), inherit: true);

        try {
            $routeScope = new HttpExecutionContext(
                $scope,
                $request,
                new RouteParams(),
                new QueryParams([]),
                RouteConfig::compile('/'),
            );
            $routes = \Phalanx\WebSocket\RouteGroup::of(['WS /socket/{id:int}' => MatchedWsRoute::class], new \Phalanx\WebSocket\Gateway());

            $match = new RouteMatcher()->match($routeScope, $routes->inner->all());

            self::assertNotNull($match);
            self::assertInstanceOf(RequestContext::class, $match->scope);
            self::assertSame('42', $match->scope->params->required('id'));
            self::assertSame(
                '/socket/{id:int}',
                $app->runtime()->memory->resources->annotation($resource->id, HttpAnnotationSid::Route),
            );
        } finally {
            $resource->release();
            $scope->dispose();
            $token->cancel();
            $app->shutdown();
        }
    }
}

final class MatchedWsRoute implements Scopeable
{
    public function __invoke(): void
    {
    }
}
