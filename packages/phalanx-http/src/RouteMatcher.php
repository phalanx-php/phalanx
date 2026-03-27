<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\ExecutionScope;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerMatcher;
use Phalanx\Handler\MatchResult;
use Psr\Http\Message\ServerRequestInterface;

final class RouteMatcher implements HandlerMatcher
{
    /** @var array<string, FastRouteCompiler> keyed by protocol */
    private array $compilers = [];

    /** @param array<string, Handler> $handlers */
    public function match(ExecutionScope $scope, array $handlers): ?MatchResult
    {
        $request = $scope->attribute('request');

        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $upgradeHeader = (string) $request->getHeaderLine('Upgrade');
        $connectionHeader = (string) $request->getHeaderLine('Connection');
        $isWsUpgrade = strtolower($upgradeHeader) === 'websocket'
            && stripos($connectionHeader, 'upgrade') !== false;
        $requestProtocol = $isWsUpgrade ? 'ws' : 'http';

        $compiler = $this->getCompiler($handlers, $requestProtocol);
        $result = $compiler->dispatch($method, $path);

        $handler = $result['handler'];
        $params = $result['params'];

        $scope = $scope->withAttribute('route.params', $params);

        foreach ($params as $name => $value) {
            $scope = $scope->withAttribute("route.$name", $value);
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
    private function getCompiler(array $handlers, string $protocol): FastRouteCompiler
    {
        if (isset($this->compilers[$protocol])) {
            return $this->compilers[$protocol];
        }

        $filtered = [];
        foreach ($handlers as $key => $handler) {
            if ($handler->config instanceof RouteConfig && $handler->config->protocol === $protocol) {
                $filtered[$key] = $handler;
            }
        }

        $compiler = new FastRouteCompiler($filtered);
        $this->compilers[$protocol] = $compiler;

        return $compiler;
    }
}
