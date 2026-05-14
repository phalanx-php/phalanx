<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Tests\Unit;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Application;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsRouteGroup;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Stoa\ExecutionContext as StoaExecutionContext;
use Phalanx\Stoa\QueryParams;
use Phalanx\Stoa\RequestCtx;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteConfig;
use Phalanx\Stoa\RouteMatcher;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\Runtime\Identity\StoaAnnotationSid;
use Phalanx\Stoa\StoaRequestDiagnostics;
use Phalanx\Stoa\StoaRequestResource;
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
        $resource = StoaRequestResource::open($app->runtime(), $request, $token, ownerScopeId: $scope->scopeId);
        $requestCtx = new RequestCtx();
        $scope->bindScopedInstance(StoaRequestResource::class, $resource, inherit: true);
        $scope->bindScopedInstance(StoaRequestDiagnostics::class, new StoaRequestDiagnostics(), inherit: true);
        $scope->bindScopedInstance(RequestCtx::class, $requestCtx, inherit: true);

        try {
            $routeScope = new StoaExecutionContext(
                $scope,
                $request,
                new RouteParams(),
                new QueryParams([]),
                RouteConfig::compile('/'),
                $requestCtx,
            );
            $routes = WsRouteGroup::of(['WS /socket/{id:int}' => MatchedWsRoute::class], new WsGateway());

            $match = (new RouteMatcher())->match($routeScope, $routes->inner->all());

            self::assertNotNull($match);
            self::assertInstanceOf(RequestScope::class, $match->scope);
            self::assertSame('42', $match->scope->params->required('id'));
            self::assertSame(
                '/socket/{id:int}',
                $app->runtime()->memory->resources->annotation($resource->id, StoaAnnotationSid::Route),
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
