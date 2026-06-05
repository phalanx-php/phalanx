<?php

declare(strict_types=1);

namespace Phalanx\Http;

use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Phalanx\AppHost;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Http\Http\Upgrade\UpgradeRegistry;
use Phalanx\Http\Response\BufferEventDispatcher;
use Phalanx\Http\Response\DefaultErrorResponseRenderer;
use Phalanx\Http\Response\ErrorResponseRenderer;
use Phalanx\Http\Response\HtmlErrorResponseRenderer;
use Phalanx\Http\Response\IgnitionErrorResponseRenderer;
use Phalanx\Http\Runtime\Identity\HttpEventSid;
use Phalanx\Http\Sse\SseStream;
use Phalanx\Registry\RegistryScope;
use Phalanx\Scope\ExecutionLifecycleScope;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Scope\Scope;
use Phalanx\Server\ServerStats;
use Phalanx\Supervisor\DispatchMode;
use Phalanx\Support\SignalHandler;
use Phalanx\Trace\TraceType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Timer;
use Throwable;

/**
 * Native Http HTTP runner assembled by HttpApplication.
 *
 * Bootstrap files should use `Http::starting($context)->routes(...)->run()`
 * so route loading, server config, and Runtime host setup stay behind the
 * Http facade.
 */
final class HttpRunner
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

    /** @var array<string, HttpRequestResource> */
    private array $activeRequestsById = [];

    /** @var array<int, HttpRequestResource> */
    private array $activeRequestsByFd = [];

    private readonly UpgradeRegistry $upgrades;

    /** @var list<ErrorResponseRenderer> */
    private array $errorRenderers = [];

    /** @param list<ErrorResponseRenderer> $errorRenderers */
    private function __construct(
        private readonly AppHost $app,
        private readonly HttpServerConfig $config = new HttpServerConfig(),
        private readonly HttpRequestFactory $requestFactory = new HttpRequestFactory(),
        private readonly HttpResponseWriter $responseWriter = new HttpResponseWriter(),
        array $errorRenderers = [],
    ) {
        $this->bufferEvents = new BufferEventDispatcher();
        $this->upgrades = new UpgradeRegistry();
        $this->errorRenderers = array_values($errorRenderers);
    }

    /** @param list<ErrorResponseRenderer> $errorRenderers */
    public static function from(
        AppHost $app,
        HttpServerConfig $config = new HttpServerConfig(),
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
        $this->server->on('request', $this->handleHttpRequest(...));
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
        $response = $this->app->runManaged(fn (): ?ResponseInterface => $this->handleRequest($request));

        if (!$response instanceof ResponseInterface) {
            throw new RuntimeException('Http dispatch did not produce a response.');
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
    private static function serverOptions(HttpServerConfig $config): array
    {
        $options = [
            'worker_num' => 1,
            'enable_coroutine' => true,
            'log_level' => SWOOLE_LOG_WARNING,
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

    private static function resolveBanner(string $banner, string $listen): string
    {
        [$host, $port] = self::parseListen($listen);
        $displayHost = $host === '0.0.0.0' ? '127.0.0.1' : $host;
        $url = "http://{$displayHost}:{$port}";

        return str_replace(['{listen}', '{url}'], [$listen, $url], $banner);
    }

    private function onServerStart(Server $server): void
    {
        $this->running = true;
        $this->serverStats ??= ServerStats::fromServer($server);
        $this->app->trace()->log(TraceType::LifecycleStartup, 'ready', ['listen' => $this->listenAddress]);
        if (!$this->config->quiet) {
            if ($this->config->banner !== null) {
                echo self::resolveBanner($this->config->banner, $this->listenAddress) . "\n";
            } else {
                printf("Phalanx Server listening on %s\n", $this->listenAddress);
            }
        }
        SignalHandler::register($this->shutdownSwooleServer(...));
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

    private function handleHttpRequest(Request $request, Response $response): void
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

        $this->abortRequest($request, HttpEventSid::ClientDisconnected, 'client disconnected');
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
            if (!$rootScope instanceof ExecutionLifecycleScope) {
                throw new RuntimeException('createScope() must return ExecutionLifecycleScope');
            }
            $errorScope = $rootScope;

            $ownerScopeId = $rootScope->scopeId;
            $resource = HttpRequestResource::open($this->app->runtime(), $request, $token, $fd, $ownerScopeId);
            $this->registerRequest($resource);
            $registered = true;
            $resource->activate();

            $diagnostics = new HttpRequestDiagnostics();
            $rootScope->bindScopedInstance(HttpRequestResource::class, $resource, inherit: true);
            $rootScope->bindScopedInstance(HttpRequestDiagnostics::class, $diagnostics, inherit: true);
            if ($target !== null) {
                $rootScope->bindScopedInstance(ResponseSink::class, new ResponseSink($target), inherit: true);
            }

            $scope = $rootScope;
            $trace = $scope->trace();
            $trace->clear();

            $scope = new ExecutionContext(
                $rootScope,
                $request,
                new RouteParams([]),
                new QueryParams($request->getQueryParams()),
                RouteConfig::compile('/'),
            );
            $errorScope = $scope;

            if ($this->draining) {
                $resource->event(HttpEventSid::ServerDrainingRejected);

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
                    $resource->event(HttpEventSid::HttpUpgradeRejected, $upgradeToken);
                    $rejection = $this->jsonResponse(426, ['error' => 'Upgrade Required']);
                    $advertised = implode(', ', $this->upgrades->tokens());
                    if ($advertised !== '') {
                        $rejection = $rejection->withHeader('Upgrade', $advertised);
                    }

                    return $this->finish($rejection, $target, $resource);
                }

                $resource->event(HttpEventSid::HttpUpgradeRequested, $upgradeToken);
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

            $requestFailureTree = null;
            try {
                $supervisor = $this->app->supervisor();
                $requestRun = $supervisor->start(
                    task: static fn() => null,
                    parent: $rootScope,
                    mode: DispatchMode::Inline,
                    name: 'HttpRequest: ' . $resource->path
                );
                $supervisor->markRunning($requestRun);
                $rootScope->currentRun = $requestRun;

                try {
                    $result = $routes->dispatch($request, $rootScope);

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
                    $requestFailureTree = $supervisor->tree();

                    throw $e;
                } catch (Throwable $e) {
                    $supervisor->fail($requestRun, $e);
                    $requestFailureTree = $requestRun->failureTree;

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

                $tree = $requestFailureTree ?? $this->app->supervisor()->tree();
                $diagnostics->recordFailureTree($tree);
                $response = $this->errorResponse($errorScope, $e, $resource);
            } catch (Throwable $e) {
                if ($e instanceof ToResponse) {
                    $response = $e->toResponse();
                } else {
                    $resource->fail($e);
                    $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);

                    $tree = $requestFailureTree ?? $this->app->supervisor()->tree();
                    $diagnostics->recordFailureTree($tree);
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
        HttpRequestResource $request,
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
                $this->recordRequestEvent($request, HttpEventSid::ResponseWriteFailed, $e::class);
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

    private function normalizeResponseBody(ResponseInterface $response, HttpRequestResource $request): ResponseInterface
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

    private function registerRequest(HttpRequestResource $request): void
    {
        $this->activeRequestsById[$request->id] = $request;

        if ($request->fd !== null) {
            $this->activeRequestsByFd[$request->fd] = $request;
        }
    }

    private function unregisterRequest(HttpRequestResource $request): void
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
            $this->abortActiveRequests(HttpEventSid::ServerShutdown, 'server shutdown');
            if ($shouldShutdownServer) {
                $this->shutdownSwooleServer($server);
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
            $this->shutdownSwooleServer($server);
        }
    }

    private function shutdownSwooleServer(?Server $server = null): void
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
        $this->abortActiveRequests(HttpEventSid::DrainTimeout, 'drain timeout');
        $this->checkDrainComplete();
    }

    private function abortActiveRequests(HttpEventSid $event, string $reason): void
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

    private function abortRequest(HttpRequestResource $request, HttpEventSid $event, string $reason): void
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
        HttpRequestResource $request,
        HttpEventSid $event,
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

    private function errorResponse(Scope $scope, Throwable $e, HttpRequestResource $request): ResponseInterface
    {
        $requestScope = $scope instanceof RequestContext ? $scope : null;
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

        /**
         * Extremely rare edge case: create a minimal context for the default
         * renderer. When this path allocates a fresh scope, dispose it after
         * the render completes.
         */
        $ownedScope = null;
        if ($scope instanceof ExecutionScope) {
            $inner = $scope;
        } else {
            $ownedScope = $this->app->createScope();
            $inner = $ownedScope;
        }

        try {
            $dummy = new ExecutionContext(
                $inner,
                new \GuzzleHttp\Psr7\ServerRequest('GET', $request->path),
                new RouteParams([]),
                new QueryParams([]),
                RouteConfig::compile('/'),
            );

            return $defaultRenderer->render($dummy, $e);
        } finally {
            $ownedScope?->dispose();
        }
    }
}
