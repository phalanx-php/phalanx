<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Server;

use Phalanx\AppHost;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Http\ExecutionContext as HttpExecutionContext;
use Phalanx\Http\QueryParams;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteConfig;
use Phalanx\Http\RouteMatcher;
use Phalanx\Http\RouteParams;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\WebSocket\ExecutionContext as WsExecutionContext;
use Phalanx\WebSocket\Runtime\Identity\WebSocketEventSid;
use Phalanx\WebSocket\Runtime\Identity\WebSocketResourceSid;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swoole\Http\Response as SwooleHttpResponse;
use Throwable;

/**
 * WebSocket-side {@see \Phalanx\Http\Upgrade\Upgradeable} implementation.
 *
 * Once `$target->upgrade()` returns true the underlying connection is in
 * WebSocket protocol state at the Swoole C layer; sending an HTTP
 * response back is undefined. Therefore any post-handshake exception is
 * converted to a terminal-resource state and a clean return — never
 * propagated back to HTTP.
 */
final class Upgrade implements \Phalanx\Http\Upgrade\Upgradeable
{
    public function __construct(
        private readonly AppHost $app,
        private readonly \Phalanx\WebSocket\RouteGroup $routes,
        private readonly \Phalanx\WebSocket\Gateway $gateway,
        private(set) RouteMatcher $routeMatcher = new RouteMatcher(),
    ) {
    }

    public function upgrade(
        ServerRequestInterface $request,
        SwooleHttpResponse $target,
        \Phalanx\Http\RequestResource $requestResource,
    ): ManagedResourceHandle {
        $resources = $this->app->runtime()->memory->resources;

        $sessionToken = CancellationToken::create();
        $sessionScope = $this->app->createScope($sessionToken);
        if (!$sessionScope instanceof ExecutionLifecycleScope) {
            throw new RuntimeException('createScope() must return ExecutionLifecycleScope');
        }
        $sessionScope->bindScopedInstance(\Phalanx\Http\RequestResource::class, $requestResource, inherit: true);
        $sessionScope->bindScopedInstance(\Phalanx\Http\RequestDiagnostics::class, new \Phalanx\Http\RequestDiagnostics(), inherit: true);

        $routeScope = new HttpExecutionContext(
            $sessionScope,
            $request,
            new RouteParams(),
            new QueryParams($request->getQueryParams()),
            RouteConfig::compile('/'),
        );

        $match = $this->routeMatcher->match($routeScope, $this->routes->inner->all());
        if ($match === null) {
            $sessionScope->dispose();
            $resources->recordEvent($requestResource->id, WebSocketEventSid::ServerUpgradeRejected, 'no_route');

            return $resources->fail($requestResource->id, 'no_route');
        }

        if (!$match->scope instanceof RequestContext) {
            $sessionScope->dispose();
            $resources->recordEvent($requestResource->id, WebSocketEventSid::ServerUpgradeRejected, 'invalid_route_scope');

            return $resources->fail($requestResource->id, 'invalid_route_scope');
        }

        $handler = $match->handler;
        $wsConfig = $handler->config instanceof \Phalanx\WebSocket\RouteConfig
            ? $handler->config->wsConfig
            : new \Phalanx\WebSocket\Config();

        $params = $match->scope->params;

        $handshakeOk = false;
        try {
            $handshakeOk = $target->upgrade();
        } catch (Cancelled $cancelled) {
            $sessionScope->dispose();

            throw $cancelled;
        } catch (Throwable $e) {
            $sessionScope->dispose();
            $resources->recordEvent($requestResource->id, WebSocketEventSid::HandshakeFailed, $e::class);

            return $resources->fail($requestResource->id, 'handshake_error');
        }

        if (!$handshakeOk) {
            $sessionScope->dispose();
            $resources->recordEvent($requestResource->id, WebSocketEventSid::HandshakeFailed, 'upgrade_returned_false');

            return $resources->fail($requestResource->id, 'handshake_failed');
        }

        $wsHandle = $resources->upgrade(
            $requestResource->id,
            WebSocketResourceSid::WebSocketServerConnection,
        );
        $resources->recordEvent($wsHandle, WebSocketEventSid::ServerUpgradeAccepted);
        $resources->recordEvent($wsHandle, WebSocketEventSid::ConnectionOpened, $request->getUri()->getPath());

        $requestCancellation = $requestResource->cancellation();
        $cancelKey = $requestCancellation->onCancel(
            static function () use ($sessionToken): void {
                $sessionToken->cancel();
            },
        );

        $connection = new \Phalanx\WebSocket\Connection($wsHandle->id);

        $sessionScopeForHandler = $match->scope;

        $server = new \Phalanx\WebSocket\Server\Connection(
            scope: $sessionScopeForHandler,
            target: $target,
            config: $wsConfig,
            connection: $connection,
            resource: $wsHandle,
            resources: $resources,
            gateway: $this->gateway,
            host: $request->getUri()->getHost(),
        );

        $resolver = $sessionScopeForHandler->service(HandlerResolver::class);
        $pump = $resolver->resolve($sessionScopeForHandler, $handler->task);
        if (!is_callable($pump)) {
            self::unlink($requestCancellation, $cancelKey);
            $server->close();
            $sessionScope->dispose();
            $resources->recordEvent($wsHandle, WebSocketEventSid::ConnectionFailed, 'handler_not_callable');

            return $resources->fail($wsHandle, 'handler_not_callable');
        }

        $wsScope = new WsExecutionContext(
            $sessionScopeForHandler,
            $connection,
            $wsConfig,
            $request,
            $params,
        );

        try {
            $pump($wsScope);
        } catch (Cancelled $cancelled) {
            $resources->recordEvent($wsHandle, WebSocketEventSid::ConnectionAborted, 'cancelled');
            self::unlink($requestCancellation, $cancelKey);
            $server->close();
            $sessionScope->dispose();
            $resources->abort($wsHandle, 'cancelled');

            throw $cancelled;
        } catch (Throwable $e) {
            $resources->recordEvent($wsHandle, WebSocketEventSid::ConnectionFailed, $e::class);
            self::unlink($requestCancellation, $cancelKey);
            $server->close();
            $sessionScope->dispose();

            return $resources->fail($wsHandle, $e::class);
        }

        self::unlink($requestCancellation, $cancelKey);
        $server->close();

        try {
            $resources->recordEvent($wsHandle, WebSocketEventSid::ConnectionClosed, 'session_ended');
            $wsHandle = $resources->close($wsHandle, 'session_ended');
        } catch (Cancelled $cancelled) {
            throw $cancelled;
        } catch (Throwable) {
        } finally {
            try {
                $sessionScope->dispose();
            } catch (Cancelled $cancelled) {
                throw $cancelled;
            } catch (Throwable) {
            }
        }

        return $wsHandle;
    }

    private static function unlink(CancellationToken $token, int $key): void
    {
        $token->offCancel($key);
    }
}
