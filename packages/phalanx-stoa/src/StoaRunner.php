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
use Phalanx\Scope\ScopeIdentity;
use Phalanx\Supervisor\TaskTreeFormatter;
use Phalanx\Support\SignalHandler;
use Phalanx\Trace\TraceType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

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

    /** @var array<string, StoaRequestResource> */
    private array $activeRequestsById = [];

    /** @var array<int, StoaRequestResource> */
    private array $activeRequestsByFd = [];

    private function __construct(
        private readonly AppHost $app,
        private readonly StoaServerConfig $config = new StoaServerConfig(),
        private readonly StoaRequestFactory $requestFactory = new StoaRequestFactory(),
        private readonly StoaResponseWriter $responseWriter = new StoaResponseWriter(),
    ) {
    }

    public static function from(
        AppHost $app,
        StoaServerConfig $config = new StoaServerConfig(),
    ): self {
        return new self($app, $config);
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
        $this->server = new Server($host, $port);
        $this->server->set([
            'worker_num' => 1,
            'enable_coroutine' => true,
            'log_level' => Constant::LOG_WARNING,
            'max_wait_time' => max(1, (int) ceil($this->config->drainTimeout)),
        ]);

        $this->server->on('start', function () use ($listen): void {
            $this->running = true;
            $this->app->trace()->log(TraceType::LifecycleStartup, 'ready', ['listen' => $listen]);
            if (!$this->config->quiet) {
                printf("Phalanx Server listening on %s\n", $listen);
            }
            SignalHandler::register($this->shutdownOpenSwooleServer(...));
        });
        $this->server->on('managerStart', function (): void {
            SignalHandler::ignoreShutdownSignals();
        });
        $this->server->on('workerStart', $this->startupWorker(...));
        $this->server->on('workerStop', $this->shutdownWorker(...));
        $this->server->on('request', $this->handleStoaRequest(...));
        $this->server->on('close', $this->handleClose(...));
        $this->server->on('shutdown', function (): void {
            $this->running = false;
        });

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

        $timerId = Timer::after(max(1, (int) round($this->config->drainTimeout * 1000)), function (): void {
            $this->drainTimer = null;
            foreach ($this->activeRequestsById as $request) {
                $request->event('stoa.drain_timeout');
                $request->abort('drain timeout');
            }
            $this->checkDrainComplete();
        });
        $this->drainTimer = is_int($timerId) ? $timerId : null;

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

    public function activeRequests(): int
    {
        if ($this->activeRequestsById === []) {
            return 0;
        }

        return $this->app->runtime()->memory->resources->liveCount(StoaRequestResource::TYPE);
    }

    public function isDraining(): bool
    {
        return $this->draining;
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
        $this->shutdownAppOnce();
        $this->workerStarted = false;
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

        $request->event('stoa.client_disconnected');
        $request->abort('client disconnected');
    }

    private function handleRequest(
        ServerRequestInterface $request,
        ?int $fd = null,
        ?Response $target = null,
    ): ?ResponseInterface {
        $token = CancellationToken::timeout($this->config->requestTimeout);
        $rootScope = $this->app->createScope($token);
        $scope = $rootScope->withAttribute('request', $request);
        $ownerScopeId = $scope instanceof ScopeIdentity ? $scope->scopeId : null;
        $resource = StoaRequestResource::open($this->app->runtime(), $request, $token, $fd, $ownerScopeId);
        $resource->activate();
        $this->registerRequest($resource);

        $scope = $scope
            ->withAttribute('stoa.resource_id', $resource->id)
            ->withAttribute('stoa.request_resource', $resource);
        $trace = $scope->trace();
        $trace->clear();

        try {
            if ($this->draining) {
                $resource->event('stoa.server_draining_rejected');
                return $this->finish(
                    $this->jsonResponse(503, ['error' => 'Server Shutting Down']),
                    $target,
                    $resource,
                );
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
                $result = $scope->execute($routes);
                $response = $result instanceof ResponseInterface
                    ? $result
                    : self::toResponse($result);
            } catch (Throwable $e) {
                if ($e instanceof ToResponse) {
                    $response = $e->toResponse();
                } else {
                    $resource->fail($e);
                    $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);
                    $response = $this->errorResponse($e, $resource);
                }
            }

            return $this->finish($response, $target, $resource);
        } finally {
            $this->unregisterRequest($resource);
            $rootScope->dispose();
            $token->cancel();
            $resource->release();
            $this->checkDrainComplete();
        }
    }

    private function finish(ResponseInterface $response, ?Response $target, StoaRequestResource $request): ?ResponseInterface
    {
        $response = $this->normalizeResponseForMethod($response, $request);
        $response = $this->applyResponseDefaults($response);
        $request->responseStatus($response->getStatusCode());

        if ($target === null) {
            $request->complete($response->getStatusCode());
            return $response;
        }

        try {
            $this->responseWriter->write($response, $target, $request);
            $request->complete($response->getStatusCode());
        } catch (ResponseWriteFailure $e) {
            $request->event('stoa.response.write_failed', $e::class);
            $request->fail($e);
            $this->app->trace()->log(TraceType::Failed, 'response', [
                'path' => $request->path,
                'state' => $request->stateValue(),
                'error' => $e->getMessage(),
                'method' => $request->method,
            ]);

            if ($target->isWritable()) {
                $target->close();
            }
        }

        return null;
    }

    private function normalizeResponseForMethod(ResponseInterface $response, StoaRequestResource $request): ResponseInterface
    {
        if ($request->method !== 'HEAD') {
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
        $this->draining = false;

        if ($this->drainTimer !== null) {
            Timer::clear($this->drainTimer);
            $this->drainTimer = null;
        }

        foreach ($this->activeRequestsById as $request) {
            $request->event('stoa.server_shutdown');
            $request->abort('server shutdown');
        }
        $this->activeRequestsById = [];
        $this->activeRequestsByFd = [];

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

    /** @param array<string, mixed> $body */
    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    private function errorResponse(Throwable $e, StoaRequestResource $request): ResponseInterface
    {
        $body = [
            'error' => 'Internal Server Error',
        ];

        if ($this->config->debug) {
            $body['message'] = $e->getMessage();
            $body['request'] = [
                'id' => $request->id,
                'path' => $request->path,
                'state' => $request->stateValue(),
                'method' => $request->method,
            ];
            $body['trace'] = $this->formatTrace($e);
            $body['tasks'] = (new TaskTreeFormatter())->format($this->app->supervisor()->tree());
        }

        return $this->jsonResponse(500, $body);
    }

    /** @return list<string> */
    private function formatTrace(Throwable $e): array
    {
        $trace = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $func = $frame['function'];
            $class = isset($frame['class']) ? $frame['class'] . '::' : '';
            $trace[] = "{$class}{$func} at {$file}:{$line}";
        }

        return array_slice($trace, 0, 10);
    }
}
