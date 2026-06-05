<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerMatcher;
use Phalanx\Handler\MatchResult;
use Phalanx\Scope\ExecutionScope;

final class RouteMatcher implements HandlerMatcher
{
    private ?FastRouteCompiler $compiler = null;

    /** @param array<string, Handler> $handlers */
    public function match(ExecutionScope $scope, array $handlers): ?MatchResult
    {
        if (!$scope instanceof RequestContext) {
            return null;
        }

        $method = $scope->request->getMethod();
        $path = $scope->request->getUri()->getPath();

        $this->compiler ??= new FastRouteCompiler($handlers);
        $result = $this->compiler->dispatch($method, $path);

        $handler = $result['handler'];
        $params = new RouteParams($result['params']);

        assert($handler->config instanceof RouteConfig);
        $resource = \Phalanx\Http\RequestResource::fromScope($scope);
        if ($resource !== null) {
            $resource->routeMatched($handler->config->path);
        }

        $scope = new ExecutionContext(
            $scope,
            $scope->request,
            $params,
            $scope->query,
            $handler->config,
        );

        return new MatchResult($handler, $scope);
    }
}
