<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use OpenSwoole\Constant;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Timer;
use Phalanx\AppHost;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Registry\RegistryScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Server\ServerStats;
use Phalanx\Stoa\Http\Upgrade\UpgradeRegistry;
use Phalanx\Stoa\Response\BufferEventDispatcher;
use Phalanx\Stoa\Response\DefaultErrorResponseRenderer;
use Phalanx\Stoa\Response\ErrorResponseRenderer;
use Phalanx\Stoa\Response\HtmlErrorResponseRenderer;
use Phalanx\Stoa\Response\IgnitionErrorResponseRenderer;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Stoa\Sse\SseStream;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Support\SignalHandler;
use Phalanx\Trace\TraceType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

/**
 * Native Stoa HTTP runner assembled by StoaApplication.
 *
 * Bootstrap files should use `Stoa::starting($context)->routes(...)->run()`
 * so route loading, server config, and Aegis host setup stay behind the
 * Stoa facade.
 */
final class StoaRunner
{
    private bool $running = false;
    private bool $draining = false;
    private bool $serverShutdownRequested = false;
    private bool $workerStarted = false;
    private bool $appShutdown = false;
    private ?int $drainTimer = null;
    private ?Server $server = null;
    private ?RouteGroup $routes = null;
    private ?ServerStats $serverStats = null;
    private string $listenAddress = '';
    private readonly BufferEventDispatcher $bufferEvents;

    /** @var array<string, StoaRequestResource> */
    private array $activeRequestsById = [];

    /** @var array<int, StoaRequestResource> */
    private array $activeRequestsByFd = [];

    private readonly UpgradeRegistry $upgrades;

    /** @var list<ErrorResponseRenderer> */
    private array $errorRenderers = [];

    private function __construct(
        private readonly AppHost $app,
        private readonly StoaServerConfig $config = new StoaServerConfig(),
        private readonly StoaRequestFactory $requestFactory = new StoaRequestFactory(),
        private readonly StoaResponseWriter $responseWriter = new StoaResponseWriter(),
        /** @var list<ErrorResponseRenderer> */
        array $errorRenderers = [],
    ) {
        $this->bufferEvents = new BufferEventDispatcher();
        $this->upgrades = new UpgradeRegistry();
        $this->errorRenderers = array_values($errorRenderers);
    }

    /** @param list<ErrorResponseRenderer> $errorRenderers */
    public static function from(
        AppHost $app,
        StoaServerConfig $config = new StoaServerConfig(),
        array $errorRenderers = [],
    ): self {
        return new self($app, $config, errorRenderers: $errorRenderers);
    }

    public static function toResponse(mixed $data): ResponseInterface
    {
        if ($data instanceof ResponseInterface) {
            return $data;
        }

        if ($data instanceof ToResponse) {
            return $data->toResponse();
        }

        if (is_array($data) || is_object($data)) {
            return new PsrResponse(
                200,
                ['Content-Type' => 'application/json'],
                json_encode($data, JSON_THROW_ON_ERROR),
            );
        }

        if (is_string($data)) {
            return new PsrResponse(200, ['Content-Type' => 'text/plain'], $data);
        }

        return new PsrResponse(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['result' => $data], JSON_THROW_ON_ERROR),
        );
    }

    /** @param RouteGroup|string|list<string> $routes */
    public function withRoutes(RouteGroup|string|array $routes): self
    {
        if (is_string($routes) || is_array($routes)) {
            $routes = self::loadRoutes($this->app, $routes);
        }

        $this->routes = $this->routes !== null
            ? $this->routes->merge($routes)
            : $routes;

        return $this;
    }

    public function run(string $listen = '0.0.0.0:8080'): int
    {
        if ($this->routes === null) {
            throw new RuntimeException('No routes configured. Call withRoutes() before run().');
        }

        [$host, $port] = self::parseListen($listen);
        $this->listenAddress = $listen;
        $this->server = new Server($host, $port);
        $this->server->set(self::serverOptions($this->config));

        $this->server->on('start', $this->onServerStart(...));
        $this->server->on('managerStart', $this->onManagerStart(...));
        $this->server->on('workerStart', $this->startupWorker(...));
        $this->server->on('workerStop', $this->shutdownWorker(...));
        $this->server->on('request', $this->handleStoaRequest(...));
        $this->server->on('close', $this->handleClose(...));
        $this->server->on('shutdown', $this->onServerShutdown(...));
        $this->bufferEvents->attach($this->server);

        try {
            $this->server->start();
        } finally {
            $this->finalize();
        }

        return 0;
    }

    public function stop(): void
    {
        if ($this->draining) {
            return;
        }

        $this->draining = true;

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'drain', [
            'active' => $this->activeRequests(),
            'timeout' => $this->config->drainTimeout,
        ]);

        $this->scheduleDrainTimer();
        $this->checkDrainComplete();
    }

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->handleRequest($request);

        if (!$response instanceof ResponseInterface) {
            throw new RuntimeException('Stoa dispatch did not produce a response.');
        }

        return $response;
    }

    public function activeRequests(?RegistryScope $scope = null): int
    {
        $scope ??= RegistryScope::Worker;

        if ($scope === RegistryScope::Server) {
            return $this->serverStats?->liveConnections(RegistryScope::Server) ?? count($this->activeRequestsById);
        }

        return count($this->activeRequestsById);
    }

    /** @return array<string, int> */
    public function activeRequestsByState(?RegistryScope $scope = null): array
    {
        $scope ??= RegistryScope::Worker;

        if ($scope === RegistryScope::Server) {
            return [];
        }

        $byState = [];

        foreach ($this->activeRequestsById as $request) {
            $state = $request->stateValue();
            $byState[$state] = ($byState[$state] ?? 0) + 1;
        }

        return $byState;
    }

    public function withServerStats(ServerStats $serverStats): self
    {
        $this->serverStats = $serverStats;
        return $this;
    }

    public function upgrades(): UpgradeRegistry
    {
        return $this->upgrades;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    /** @return array<string, mixed> */
    private static function serverOptions(StoaServerConfig $config): array
    {
        $options = [
            'worker_num' => 1,
            'enable_coroutine' => true,
            'log_level' => Constant::LOG_WARNING,
            'max_wait_time' => max(1, (int) ceil($config->drainTimeout)),
            'http_compression' => $config->httpCompression,
        ];

        if ($config->enableStaticHandler && $config->documentRoot !== null) {
            $options['enable_static_handler'] = true;
            $options['document_root'] = $config->documentRoot;
        }

        return $options;
    }

    private static function upgradeToken(ServerRequestInterface $request): ?string
    {
        $upgrade = $request->getHeaderLine('Upgrade');
        if ($upgrade === '') {
            return null;
        }

        $connection = strtolower($request->getHeaderLine('Connection'));
        if (!str_contains($connection, 'upgrade')) {
            return null;
        }

        return strtolower(trim(explode(',', $upgrade)[0]));
    }

    /** @return array{string, int} */
    private static function parseListen(string $listen): array
    {
        $separator = strrpos($listen, ':');

        if ($separator === false) {
            throw new RuntimeException("Invalid listen address: {$listen}");
        }

        $host = substr($listen, 0, $separator);
        $port = (int) substr($listen, $separator + 1);

        if ($host === '' || $port <= 0) {
            throw new RuntimeException("Invalid listen address: {$listen}");
        }

        return [$host, $port];
    }

    /** @param string|list<string> $paths */
    private static function loadRoutes(AppHost $app, string|array $paths): RouteGroup
    {
        $paths = is_string($paths) ? [$paths] : $paths;
        $scope = $app->createScope();
        $group = RouteGroup::of([]);

        try {
            foreach ($paths as $dir) {
                $group = $group->merge(RouteLoader::loadDirectory($dir, $scope));
            }
        } finally {
            $scope->dispose();
        }

        return $group;
    }

    private function onServerStart(Server $server): void
    {
        $this->running = true;
        $this->serverStats ??= ServerStats::fromServer($server);
        $this->app->trace()->log(TraceType::LifecycleStartup, 'ready', ['listen' => $this->listenAddress]);
        if (!$this->config->quiet) {
            printf("Phalanx Server listening on %s\n", $this->listenAddress);
        }
        SignalHandler::register($this->shutdownOpenSwooleServer(...));
    }

    private function onManagerStart(Server $server): void
    {
        SignalHandler::ignoreShutdownSignals();
    }

    private function onServerShutdown(Server $server): void
    {
        $this->running = false;
    }

    private function startupWorker(Server $server, int $workerId): void
    {
        if ($this->workerStarted) {
            return;
        }

        SignalHandler::register($this->stop(...));
        $this->app->startup();
        $this->appShutdown = false;
        $this->workerStarted = true;
        $this->app->trace()->log(TraceType::LifecycleStartup, 'worker', ['worker' => $workerId]);
    }

    private function shutdownWorker(Server $server, int $workerId): void
    {
        if (!$this->workerStarted) {
            return;
        }

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'worker', ['worker' => $workerId]);
        $this->finalize();
    }

    private function handleStoaRequest(Request $request, Response $response): void
    {
        $this->handleRequest(
            $this->requestFactory->create($request),
            $request->fd > 0 ? $request->fd : null,
            $response,
        );
    }

    private function handleClose(Server $server, int $fd): void
    {
        $request = $this->activeRequestsByFd[$fd] ?? null;

        if ($request === null) {
            return;
        }

        $this->abortRequest($request, StoaEventSid::ClientDisconnected, 'client disconnected');
    }

    private function handleRequest(
        ServerRequestInterface $request,
        ?int $fd = null,
        ?Response $target = null,
    ): ?ResponseInterface {
        $registered = false;
        $rootScope = null;
        $resource = null;
        $token = null;
        $errorScope = null;

        try {
            $token = CancellationToken::timeout($this->config->requestTimeout);
            $rootScope = $this->app->createScope($token);
            $errorScope = $rootScope;

            $scope = $rootScope->withAttribute('request', $request);
            $ownerScopeId = $scope instanceof ScopeIdentity ? $scope->scopeId : null;
            $resource = StoaRequestResource::open($this->app->runtime(), $request, $token, $fd, $ownerScopeId);
            $this->registerRequest($resource);
            $registered = true;
            $resource->activate();

            $scope = $scope
                ->withAttribute(StoaScopeKey::ResourceId->value, $resource->id)
                ->withAttribute(StoaScopeKey::RequestResource->value, $resource);
            if ($target !== null) {
                $scope = $scope->withAttribute(StoaScopeKey::OpenSwooleResponse->value, $target);
            }
            $trace = $scope->trace();
            $trace->clear();

            $scope = new ExecutionContext(
                $scope instanceof ExecutionScope ? $scope : $rootScope,
                $request,
                new RouteParams([]),
                new QueryParams($request->getQueryParams()),
                RouteConfig::compile('/'),
            );
            $errorScope = $scope;

            if ($this->draining) {
                $resource->event(StoaEventSid::ServerDrainingRejected);
                return $this->finish(
                    $this->jsonResponse(503, ['error' => 'Server Shutting Down']),
                    $target,
                    $resource,
                );
            }

            $upgradeToken = self::upgradeToken($request);
            if ($upgradeToken !== null) {
                $upgradeable = $this->upgrades->resolve($upgradeToken);

                if ($upgradeable === null || $target === null) {
                    $resource->event(StoaEventSid::HttpUpgradeRejected, $upgradeToken);
                    $rejection = $this->jsonResponse(426, ['error' => 'Upgrade Required']);
                    $advertised = implode(', ', $this->upgrades->tokens());
                    if ($advertised !== '') {
                        $rejection = $rejection->withHeader('Upgrade', $advertised);
                    }
                    return $this->finish($rejection, $target, $resource);
                }

                $resource->event(StoaEventSid::HttpUpgradeRequested, $upgradeToken);
                $upgradeable->upgrade($request, $target, $resource);
                if (!$resource->isTerminal()) {
                    $resource->complete(101);
                }
                return null;
            }

            $routes = $this->routes;
            if ($routes === null) {
                return $this->finish(
                    $this->jsonResponse(404, ['error' => 'Not Found']),
                    $target,
                    $resource,
                );
            }

            try {
                $supervisor = $this->app->supervisor();
                $requestRun = $supervisor->start(
                    task: static fn() => null, 
                    parent: $rootScope, 
                    mode: DispatchMode::Inline, 
                    name: 'StoaRequest: ' . $resource->path
                );
                $supervisor->markRunning($requestRun);
                $scope->currentRun = $requestRun;

                try {
                    $result = $scope->execute($routes);

                    if ($result instanceof SseStream) {
                        if (!$result->isClosed()) {
                            $result->close();
                        }
                        if (!$resource->isTerminal()) {
                            $resource->complete(200);
                        }
                        $supervisor->complete($requestRun, null);
                        return null;
                    }

                    $response = $result instanceof ResponseInterface
                        ? $result
                        : self::toResponse($result);
                    
                    $supervisor->complete($requestRun, $response);
                } catch (Cancelled $e) {
                    $supervisor->cancel($requestRun);
                    throw $e;
                } catch (Throwable $e) {
                    $supervisor->fail($requestRun, $e);
                    throw $e;
                } finally {
                    $supervisor->reap($requestRun);
                }
            } catch (Cancelled $e) {
                $resource->abort($e->getMessage() === '' ? 'cancelled' : $e->getMessage());
                $trace->log(TraceType::Lifecycle, 'request.cancelled', ['path' => $resource->path]);
                if ($target !== null) {
                    return null;
                }
                
                $tree = $requestRun->failureTree ?? $this->app->supervisor()->tree();
                $errorScope = $errorScope->withAttribute('phx.error_ledger', $tree);
                $response = $this->errorResponse($errorScope, $e, $resource);
            } catch (Throwable $e) {
                if ($e instanceof ToResponse) {
                    $response = $e->toResponse();
                } else {
                    $resource->fail($e);
                    $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);

                    // Snapshot ledger while tasks are still active
                    $tree = $requestRun->failureTree ?? $this->app->supervisor()->tree();
                    $errorScope = $errorScope->withAttribute('phx.error_ledger', $tree);
                    $response = $this->errorResponse($errorScope, $e, $resource);
                }
            }

            return $this->finish($response, $target, $resource);
        } finally {
            if ($registered && $resource !== null) {
                $this->unregisterRequest($resource);
            }
            if ($rootScope !== null) {
                $rootScope->dispose();
            }
            if ($token !== null) {
                $token->cancel();
            }
            if ($resource !== null) {
                $resource->release();
            }
            $this->checkDrainComplete();
        }
    }

    private function finish(
        ResponseInterface $response,
        ?Response $target,
        StoaRequestResource $request,
    ): ?ResponseInterface {
        try {
            $response = $this->normalizeResponseBody($response, $request);
            $response = $this->applyResponseDefaults($response);
            $request->responseStatus($response->getStatusCode());

            if ($target === null) {
                $request->complete($response->getStatusCode());
                return $response;
            }

            if ($request->fd !== null) {
                $request->acquireDeliveryLease($request->fd);
                $this->bufferEvents->track($request->fd, $request);
            }

            $this->responseWriter->write($response, $target, $request);
            $request->complete($response->getStatusCode());
            $request->releaseDeliveryLease('fulfilled');
        } catch (ResponseWriteFailure $e) {
            if (!$request->isTerminal()) {
                $this->recordRequestEvent($request, StoaEventSid::ResponseWriteFailed, $e::class);
                $request->fail($e);
            }
            $request->releaseDeliveryLease('abandoned:write_failed');
            $this->app->trace()->log(TraceType::Failed, 'response', [
                'path' => $request->path,
                'state' => $request->stateValue(),
                'error' => $e->getMessage(),
                'method' => $request->method,
            ]);

            if ($target !== null && $target->isWritable()) {
                $target->close();
            }
        } catch (Throwable $e) {
            if (!$request->isTerminal()) {
                $request->fail($e);
            }

            $request->releaseDeliveryLease('abandoned:' . $e::class);
            $this->app->trace()->log(TraceType::Failed, 'response', [
                'path' => $request->path,
                'state' => $request->stateValue(),
                'error' => $e->getMessage(),
                'method' => $request->method,
            ]);

            if ($target !== null) {
                if ($target->isWritable()) {
                    $target->close();
                }

                return null;
            }

            throw $e;
        } finally {
            if ($request->fd !== null) {
                $this->bufferEvents->untrack($request->fd);
            }
        }

        return null;
    }

    private function normalizeResponseBody(ResponseInterface $response, StoaRequestResource $request): ResponseInterface
    {
        if ($request->method !== 'HEAD' && !in_array($response->getStatusCode(), [204, 304], true)) {
            return $response;
        }

        return $response->withBody(Utils::streamFor(''));
    }

    private function applyResponseDefaults(ResponseInterface $response): ResponseInterface
    {
        if ($this->config->poweredBy === null || $response->hasHeader('X-Powered-By')) {
            return $response;
        }

        return $response->withHeader('X-Powered-By', $this->config->poweredBy);
    }

    private function registerRequest(StoaRequestResource $request): void
    {
        $this->activeRequestsById[$request->id] = $request;

        if ($request->fd !== null) {
            $this->activeRequestsByFd[$request->fd] = $request;
        }
    }

    private function unregisterRequest(StoaRequestResource $request): void
    {
        unset($this->activeRequestsById[$request->id]);

        if ($request->fd !== null) {
            unset($this->activeRequestsByFd[$request->fd]);
        }
    }

    private function checkDrainComplete(): void
    {
        if (!$this->draining || $this->activeRequestsById !== []) {
            return;
        }

        $this->finalize();
    }

    private function finalize(): void
    {
        if (!$this->draining && !$this->running && $this->server === null && !$this->workerStarted) {
            return;
        }

        $server = $this->server;
        $shouldShutdownServer = $server !== null && $this->running;

        $this->running = false;

        if ($this->activeRequestsById !== []) {
            $this->draining = true;
            $this->scheduleDrainTimer();
            $this->abortActiveRequests(StoaEventSid::ServerShutdown, 'server shutdown');
            if ($shouldShutdownServer) {
                $this->shutdownOpenSwooleServer($server);
            }
            return;
        }

        $this->draining = false;
        if ($this->drainTimer !== null) {
            Timer::clear($this->drainTimer);
            $this->drainTimer = null;
        }

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'shutdown');
        if ($server === null || $this->workerStarted) {
            $this->shutdownAppOnce();
            $this->workerStarted = false;
        }
        $this->server = null;

        if ($shouldShutdownServer) {
            $this->shutdownOpenSwooleServer($server);
        }
    }

    private function shutdownOpenSwooleServer(?Server $server = null): void
    {
        if ($this->serverShutdownRequested) {
            return;
        }

        $this->serverShutdownRequested = true;
        ($server ?? $this->server)?->shutdown();
    }

    private function shutdownAppOnce(): void
    {
        if ($this->appShutdown) {
            return;
        }

        $this->app->shutdown();
        $this->appShutdown = true;
    }

    private function scheduleDrainTimer(): void
    {
        if ($this->drainTimer !== null) {
            return;
        }

        $timerId = Timer::after(
            max(1, (int) round($this->config->drainTimeout * 1000)),
            $this->onDrainTimeout(...),
        );
        $this->drainTimer = is_int($timerId) ? $timerId : null;
    }

    private function onDrainTimeout(): void
    {
        $this->drainTimer = null;
        $this->abortActiveRequests(StoaEventSid::DrainTimeout, 'drain timeout');
        $this->checkDrainComplete();
    }

    private function abortActiveRequests(StoaEventSid $event, string $reason): void
    {
        $cancelled = null;

        foreach ($this->activeRequestsById as $request) {
            try {
                $this->abortRequest($request, $event, $reason);
            } catch (Cancelled $e) {
                $cancelled ??= $e;
            }
        }

        if ($cancelled !== null) {
            throw $cancelled;
        }
    }

    private function abortRequest(StoaRequestResource $request, StoaEventSid $event, string $reason): void
    {
        $cancelled = null;

        try {
            $this->recordRequestEvent($request, $event);
        } catch (Cancelled $e) {
            $cancelled = $e;
        }

        try {
            $request->abort($reason);
            $request->releaseDeliveryLease('abandoned:' . $reason);
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->app->trace()->log(TraceType::Failed, 'request.abort', [
                'path' => $request->path,
                'error' => $e->getMessage(),
                'method' => $request->method,
            ]);
        }

        if ($cancelled !== null) {
            throw $cancelled;
        }
    }

    private function recordRequestEvent(
        StoaRequestResource $request,
        StoaEventSid $event,
        string $valueA = '',
        string $valueB = '',
    ): void {
        try {
            $request->event($event, $valueA, $valueB);
        } catch (Cancelled $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->app->trace()->log(TraceType::Failed, 'request.event', [
                'path' => $request->path,
                'event' => $event->value,
                'error' => $e->getMessage(),
                'method' => $request->method,
            ]);
        }
    }

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function errorResponse(Scope $scope, Throwable $e, StoaRequestResource $request): ResponseInterface
    {
        $requestScope = $scope instanceof RequestScope ? $scope : null;
        $defaultRenderer = new DefaultErrorResponseRenderer($this->config->ignitionEnabled);

        if ($requestScope !== null) {
            $renderers = array_values([
                ...$this->errorRenderers,
                new IgnitionErrorResponseRenderer($this->config),
                new HtmlErrorResponseRenderer($this->config->ignitionEnabled),
                $defaultRenderer,
            ]);

            foreach ($renderers as $renderer) {
                $response = $renderer->render($requestScope, $e);
                if ($response !== null) {
                    return $response;
                }
            }
        }

        // Extremely rare edge case: create a minimal context for the default renderer.
        // When we must allocate a fresh scope here, dispose it after the render completes.
        $ownedScope = null;
        if ($scope instanceof ExecutionScope) {
            $inner = $scope;
        } else {
            $ownedScope = $this->app->createScope();
            $inner      = $ownedScope;
        }

        try {
            $dummy = new ExecutionContext(
                $inner,
                new \GuzzleHttp\Psr7\ServerRequest('GET', $request->path),
                new RouteParams([]),
                new QueryParams([]),
                RouteConfig::compile('/'),
            );

            return $defaultRenderer->render($dummy, $e)
                ?? $this->jsonResponse(500, ['error' => 'Internal Server Error']);
        } finally {
            $ownedScope?->dispose();
        }
    }

}
