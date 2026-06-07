<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Tests\Unit;

use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Http\ExecutionContext as HttpExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteMatcher;
use Phalanx\Http\RouteParams;
use Phalanx\Http\Runtime\Identity\HttpAnnotationSid;
use Phalanx\Http\RequestDiagnostics;
use Phalanx\Http\RequestResource;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use Phalanx\Testing\PhalanxTestCase;
use Phalanx\WebSocket\Gateway;
use Phalanx\WebSocket\RouteGroup;
use PHPUnit\Framework\Attributes\Test;

final class WsRouteGroupTest extends PhalanxTestCase
{
    #[Test]
    public function websocket_routes_match_against_typed_request_scope_and_expose_params(): void
    {
        $this->scope->run(static function (ExecutionScope $scope): void {
            self::assertInstanceOf(ExecutionLifecycleScope::class, $scope);

            $request = new ServerRequest('GET', '/socket/42');
            $token = CancellationToken::create();
            $resource = RequestResource::open($scope->runtime, $request, $token, ownerScopeId: $scope->scopeId);
            $scope->bindScopedInstance(RequestResource::class, $resource, inherit: true);
            $scope->bindScopedInstance(RequestDiagnostics::class, new RequestDiagnostics(), inherit: true);

            try {
                $routeScope = new HttpExecutionContext(
                    $scope,
                    $request,
                    new RouteParams(),
                    new QueryParams([]),
                    RouteConfig::compile('/'),
                );
                $routes = RouteGroup::of(['WS /socket/{id:int}' => MatchedWsRoute::class], new Gateway());

                $match = new RouteMatcher()->match($routeScope, $routes->inner->all());

                self::assertNotNull($match);
                self::assertInstanceOf(RequestContext::class, $match->scope);
                self::assertSame('42', $match->scope->params->required('id'));
                self::assertSame(
                    '/socket/{id:int}',
                    $scope->runtime->memory->resources->annotation($resource->id, HttpAnnotationSid::Route),
                );
            } finally {
                $resource->release();
                $token->cancel();
            }
        });
    }
}

final class MatchedWsRoute implements Scopeable
{
    public function __invoke(): void
    {
    }
}
