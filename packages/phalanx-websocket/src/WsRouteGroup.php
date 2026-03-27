<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\ExecutionScope;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteMatcher;
use Phalanx\Http\RouteParams;
use Phalanx\Task\Executable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Stream\DuplexStreamInterface;

use function React\Async\async;

final class WsRouteGroup implements Executable
{
    private(set) HandlerGroup $inner;

    /** @param array<string, WsRoute> $routes */
    private function __construct(
        array $routes,
        private readonly WsGateway $gateway,
        private readonly WsHandshake $handshake,
    ) {
        $handlers = [];

        foreach ($routes as $key => $route) {
            $parsed = self::parseKey($key);

            if ($parsed === null) {
                continue;
            }

            $compiled = RouteConfig::compile($parsed, 'GET', 'ws');
            $config = new RouteConfig(
                $compiled->methods,
                $compiled->pattern,
                $compiled->paramNames,
                'ws',
                $parsed,
                $route->config->middleware,
                $route->config->tags,
                $route->config->priority,
            );

            $handlers[$key] = new Handler($route, $config);
        }

        $this->inner = HandlerGroup::of($handlers)->withMatcher(new RouteMatcher());
    }

    /** @param array<string, WsRoute> $routes */
    public static function of(
        array $routes,
        ?WsGateway $gateway = null,
        ?WsHandshake $handshake = null,
    ): self {
        return new self(
            $routes,
            $gateway ?? new WsGateway(),
            $handshake ?? new WsHandshake(),
        );
    }

    public function __invoke(ExecutionScope $scope): mixed
    {
        return ($this->inner)($scope);
    }

    public function gateway(): WsGateway
    {
        return $this->gateway;
    }

    /**
     * Creates the upgrade handler callback used by Runner::withWebsockets().
     *
     * @return callable(ExecutionScope, DuplexStreamInterface, ServerRequestInterface): ResponseInterface
     */
    public function upgradeHandler(): callable
    {
        $group = $this;

        return static function (
            ExecutionScope $scope,
            DuplexStreamInterface $transport,
            ServerRequestInterface $request,
        ) use ($group): ResponseInterface {
            $scope = $scope->withAttribute('request', $request);
            $match = new RouteMatcher()->match($scope, $group->inner->all());

            if ($match === null) {
                throw new \RuntimeException("No route matches GET {$request->getUri()->getPath()}");
            }

            $response = $group->handshake->negotiate($request);

            if (!$group->handshake->isSuccessful($response)) {
                return $response;
            }

            $handler = $match->handler;
            $wsRoute = $handler->task;
            $wsConfig = $wsRoute instanceof WsRoute ? $wsRoute->config : new WsConfig();

            $routeConfig = $handler->config;
            $params = $routeConfig instanceof RouteConfig
                ? ($routeConfig->matches('GET', $request->getUri()->getPath()) ?? [])
                : [];

            $connectionHandler = new WsConnectionHandler(
                $wsRoute,
                $wsConfig,
                $group->gateway,
            );

            async(static function () use ($connectionHandler, $match, $transport, $request, $params): void {
                $connectionHandler->handle(
                    $match->scope,
                    $transport,
                    $request,
                    new RouteParams($params),
                );
            })();

            return $response;
        };
    }

    private static function parseKey(string $key): ?string
    {
        if (preg_match('#^WS\s+(/\S*)$#i', $key, $m)) {
            return $m[1];
        }

        if (str_starts_with($key, '/')) {
            return $key;
        }

        return null;
    }
}
