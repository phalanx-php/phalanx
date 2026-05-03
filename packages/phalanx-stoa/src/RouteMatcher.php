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

        $compiler = $this->getCompiler($handlers);
        $result = $compiler->dispatch($method, $path);

        $handler = $result['handler'];
        $params = $result['params'];

        $scope = $scope->withAttribute('route.params', $params);

        foreach ($params as $name => $value) {
            $scope = $scope->withAttribute("route.$name", $value);
        }

        assert($handler->config instanceof RouteConfig);
        $resource = $scope->attribute('stoa.request_resource');
        if ($resource instanceof StoaRequestResource) {
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

    /**
     * @param array<string, Handler> $handlers
     */
    private function getCompiler(array $handlers): FastRouteCompiler
    {
        if ($this->compiler !== null) {
            return $this->compiler;
        }

        $this->compiler = new FastRouteCompiler($handlers);

        return $this->compiler;
    }
}
