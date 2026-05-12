<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Server;

use OpenSwoole\Http\Response as SwooleHttpResponse;
use Phalanx\AppHost;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Handler\HandlerResolver;
use Phalanx\Hermes\ExecutionContext as WsExecutionContext;
use Phalanx\Hermes\Runtime\Identity\HermesEventSid;
use Phalanx\Hermes\Runtime\Identity\HermesResourceSid;
use Phalanx\Hermes\WsConfig;
use Phalanx\Hermes\WsConnection;
use Phalanx\Hermes\WsGateway;
use Phalanx\Hermes\WsRouteConfig;
use Phalanx\Hermes\WsRouteGroup;
use Phalanx\Runtime\Memory\ManagedResourceHandle;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Stoa\Http\Upgrade\HttpUpgradeable;
use Phalanx\Stoa\RouteMatcher;
use Phalanx\Stoa\RouteParams;
use Phalanx\Stoa\StoaRequestResource;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Hermes-side {@see HttpUpgradeable} implementation.
 *
 * Once `$target->upgrade()` returns true the underlying connection is in
 * WebSocket protocol state at the OpenSwoole C layer; sending an HTTP
 * response back is undefined. Therefore any post-handshake exception is
 * converted to a terminal-resource state and a clean return — never
 * propagated back to Stoa.
 */
final class WsServerUpgrade implements HttpUpgradeable
{
    public function __construct(
        private AppHost $app,
        private WsRouteGroup $routes,
        private WsGateway $gateway,
        private(set) RouteMatcher $routeMatcher = new RouteMatcher(),
    ) {
    }

    public function upgrade(
        ServerRequestInterface $request,
        SwooleHttpResponse $target,
        StoaRequestResource $requestResource,
    ): ManagedResourceHandle {
        $resources = $this->app->runtime()->memory->resources;

        $sessionToken = CancellationToken::create();
        $sessionScope = $this->app->createScope($sessionToken);
        if (!$sessionScope instanceof ExecutionLifecycleScope) {
            throw new RuntimeException('createScope() must return ExecutionLifecycleScope');
        }
        $sessionScope->setResource('request', $request);

        $match = $this->routeMatcher->match($sessionScope, $this->routes->inner->all());
        if ($match === null) {
            $sessionScope->dispose();
            $resources->recordEvent($requestResource->id, HermesEventSid::ServerUpgradeRejected, 'no_route');
            return $resources->fail($requestResource->id, 'no_route');
        }

        $handler = $match->handler;
        $wsConfig = $handler->config instanceof WsRouteConfig
            ? $handler->config->wsConfig
            : new WsConfig();

        $params = $match->scope->attribute('route.params', []);
        if (!is_array($params)) {
            $params = [];
        }

        $handshakeOk = false;
        try {
            $handshakeOk = $target->upgrade();
        } catch (Cancelled $cancelled) {
            $sessionScope->dispose();
            throw $cancelled;
        } catch (Throwable $e) {
            $sessionScope->dispose();
            $resources->recordEvent($requestResource->id, HermesEventSid::HandshakeFailed, $e::class);
            return $resources->fail($requestResource->id, 'handshake_error');
        }

        if (!$handshakeOk) {
            $sessionScope->dispose();
            $resources->recordEvent($requestResource->id, HermesEventSid::HandshakeFailed, 'upgrade_returned_false');
            return $resources->fail($requestResource->id, 'handshake_failed');
        }

        $wsHandle = $resources->upgrade(
            $requestResource->id,
            HermesResourceSid::WebSocketServerConnection,
        );
        $resources->recordEvent($wsHandle, HermesEventSid::ServerUpgradeAccepted);
        $resources->recordEvent($wsHandle, HermesEventSid::ConnectionOpened, $request->getUri()->getPath());

        $requestCancellation = $requestResource->cancellation();
        $cancelKey = $requestCancellation->onCancel(
            static function () use ($sessionToken): void {
                $sessionToken->cancel();
            },
        );

        $connection = new WsConnection($wsHandle->id);

        $sessionScopeForHandler = $match->scope;

        $server = new WsServerConnection(
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
        $pump = $resolver->resolve($handler->task, $sessionScopeForHandler);
        if (!is_callable($pump)) {
            self::unlink($requestCancellation, $cancelKey);
            $server->close();
            $sessionScope->dispose();
            $resources->recordEvent($wsHandle, HermesEventSid::ConnectionFailed, 'handler_not_callable');
            return $resources->fail($wsHandle, 'handler_not_callable');
        }

        $wsScope = new WsExecutionContext(
            $sessionScopeForHandler,
            $connection,
            $wsConfig,
            $request,
            new RouteParams($params),
        );

        try {
            $pump($wsScope);
        } catch (Cancelled $cancelled) {
            $resources->recordEvent($wsHandle, HermesEventSid::ConnectionAborted, 'cancelled');
            self::unlink($requestCancellation, $cancelKey);
            $server->close();
            $sessionScope->dispose();
            $resources->abort($wsHandle, 'cancelled');
            throw $cancelled;
        } catch (Throwable $e) {
            $resources->recordEvent($wsHandle, HermesEventSid::ConnectionFailed, $e::class);
            self::unlink($requestCancellation, $cancelKey);
            $server->close();
            $sessionScope->dispose();
            return $resources->fail($wsHandle, $e::class);
        }

        self::unlink($requestCancellation, $cancelKey);
        $server->close();

        try {
            $resources->recordEvent($wsHandle, HermesEventSid::ConnectionClosed, 'session_ended');
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
