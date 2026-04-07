<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\ExecutionScope;
use Phalanx\Handler\Handler;
use Phalanx\Handler\HandlerGroup;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteMatcher;
use Phalanx\Http\RouteParams;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Stream\DuplexStreamInterface;

use function React\Async\async;

/**
 * Typed collection of WebSocket routes.
 *
 * Each route entry is either:
 *  - a class-string of a Scopeable/Executable handler (uses default WsConfig)
 *  - a tuple [class-string, WsConfig] when the route needs custom WS settings
 *
 * Handlers are resolved at upgrade time via HandlerResolver with constructor
 * injection from the service container.
 */
final class WsRouteGroup implements Executable
{
    private(set) HandlerGroup $inner;

    /**
     * @param array<string, class-string<Scopeable|Executable>|array{class-string<Scopeable|Executable>, WsConfig}> $routes
     */
    private function __construct(
        array $routes,
        private readonly WsGateway $gateway,
        private readonly WsHandshake $handshake,
    ) {
        $handlers = [];

        foreach ($routes as $key => $entry) {
            $parsed = self::parseKey($key);

            if ($parsed === null) {
                continue;
            }

            if (is_array($entry)) {
                [$class, $wsConfig] = $entry;
            } else {
                $class = $entry;
                $wsConfig = new WsConfig();
            }

            $compiled = RouteConfig::compile($parsed, 'GET', 'ws');
            $config = WsRouteConfig::fromCompiled($compiled, $wsConfig);

            $handlers[$key] = new Handler($class, $config);
        }

        $this->inner = HandlerGroup::of($handlers)->withMatcher(new RouteMatcher());
    }

    /**
     * @param array<string, class-string<Scopeable|Executable>|array{class-string<Scopeable|Executable>, WsConfig}> $routes
     */
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
            $routeConfig = $handler->config;
            $wsConfig = $routeConfig instanceof WsRouteConfig ? $routeConfig->wsConfig : new WsConfig();

            $params = $routeConfig instanceof RouteConfig
                ? ($routeConfig->matches('GET', $request->getUri()->getPath()) ?? [])
                : [];

            /** @var HandlerResolver $resolver */
            $resolver = $match->scope->service(HandlerResolver::class);
            $pump = $resolver->resolve($handler->task, $match->scope);

            $connectionHandler = new WsConnectionHandler(
                $pump,
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
