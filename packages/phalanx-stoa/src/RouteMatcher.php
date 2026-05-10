<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerMatcher;
use Phalanx\Handler\MatchResult;
use Phalanx\Scope\ExecutionScope;
use Psr\Http\Message\ServerRequestInterface;

final class RouteMatcher implements HandlerMatcher
{
    private ?FastRouteCompiler $compiler = null;

    /** @param array<string, Handler> $handlers */
    public function match(ExecutionScope $scope, array $handlers): ?MatchResult
    {
        $request = $scope->attribute('request');

        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $this->compiler ??= new FastRouteCompiler($handlers);
        $result = $this->compiler->dispatch($method, $path);

        $handler = $result['handler'];
        $params = $result['params'];

        $scope = $scope->withAttribute('route.params', $params);

        foreach ($params as $name => $value) {
            $scope = $scope->withAttribute("route.$name", $value);
        }

        assert($handler->config instanceof RouteConfig);
        $resource = StoaRequestResource::fromScope($scope);
        if ($resource !== null) {
            $resource->routeMatched($handler->config->path);
        }

        $scope = new ExecutionContext(
            $scope,
            $request,
            new RouteParams($params),
            new QueryParams($request->getQueryParams()),
            $handler->config,
        );

        return new MatchResult($handler, $scope);
    }

}
