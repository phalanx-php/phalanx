<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Timer;
use Phalanx\AppHost;
use Phalanx\Cancellation\CancellationToken;
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
    private bool $workerStarted = false;
    private int $nextRequestId = 0;
    private ?int $drainTimer = null;
    private ?Server $server = null;
    private ?RouteGroup $routes = null;

    /** @var array<int, RequestLifecycle> */
    private array $activeRuns = [];

    /** @var array<int, RequestLifecycle> */
    private array $activeRunsByFd = [];

    private function __construct(
        private readonly AppHost $app,
        private readonly float $requestTimeout = 30.0,
        private readonly float $drainTimeout = 30.0,
        private readonly bool $debug = false,
        private readonly StoaRequestFactory $requestFactory = new StoaRequestFactory(),
        private readonly StoaResponseWriter $responseWriter = new StoaResponseWriter(),
    ) {
    }

    public static function from(
        AppHost $app,
        float $requestTimeout = 30.0,
        float $drainTimeout = 30.0,
        bool $debug = false,
    ): self {
        return new self($app, $requestTimeout, $drainTimeout, $debug);
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
        ]);

        $this->server->on('start', function () use ($listen): void {
            $this->running = true;
            $this->app->trace()->log(TraceType::LifecycleStartup, 'ready', ['listen' => $listen]);
            printf("OpenSwoole server listening on %s\n", $listen);
        });
        $this->server->on('workerStart', $this->startupWorker(...));
        $this->server->on('workerStop', $this->shutdownWorker(...));
        $this->server->on('request', $this->handleStoaRequest(...));
        $this->server->on('close', $this->handleClose(...));

        SignalHandler::register($this->stop(...));

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

        $this->running = false;
        $this->draining = true;

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'drain', [
            'active' => $this->activeRequests(),
            'timeout' => $this->drainTimeout,
        ]);

        $timerId = Timer::after(max(1, (int) round($this->drainTimeout * 1000)), function (): void {
            $this->drainTimer = null;
            foreach ($this->activeRuns as $run) {
                $run->abort('drain timeout');
            }
            $this->finalize();
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
        return count($this->activeRuns);
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

        $this->app->startup();
        $this->workerStarted = true;
        $this->app->trace()->log(TraceType::LifecycleStartup, 'worker', ['worker' => $workerId]);
    }

    private function shutdownWorker(Server $server, int $workerId): void
    {
        if (!$this->workerStarted) {
            return;
        }

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'worker', ['worker' => $workerId]);
        $this->app->shutdown();
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
        $run = $this->activeRunsByFd[$fd] ?? null;

        if ($run === null) {
            return;
        }

        $run->abort('client disconnected');
    }

    private function handleRequest(
        ServerRequestInterface $request,
        ?int $fd = null,
        ?Response $target = null,
    ): ?ResponseInterface {
        $run = RequestLifecycle::open(++$this->nextRequestId, $request, $fd);

        if ($this->draining) {
            $response = $this->jsonResponse(503, ['error' => 'Server Shutting Down']);
            $run->complete();
            return $this->finish($response, $target, $run);
        }

        $routes = $this->routes;
        if ($routes === null) {
            $response = $this->jsonResponse(404, ['error' => 'Not Found']);
            $run->complete();
            return $this->finish($response, $target, $run);
        }

        $token = CancellationToken::timeout($this->requestTimeout);
        $run->attach($token);
        $this->registerRun($run);

        $scope = $this->app->createScope($token)
            ->withAttribute('request', $request)
            ->withAttribute('stoa.request_lifecycle', $run);
        $trace = $scope->trace();
        $trace->clear();

        try {
            try {
                $result = $scope->execute($routes);
                $response = $result instanceof ResponseInterface
                    ? $result
                    : self::toResponse($result);
            } catch (Throwable $e) {
                if ($e instanceof ToResponse) {
                    $response = $e->toResponse();
                } else {
                    $run->fail($e);
                    $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);
                    $response = $this->errorResponse($e, $run);
                }
            }

            return $this->finish($response, $target, $run);
        } finally {
            $this->unregisterRun($run);
            $scope->dispose();
            $token->cancel();
            $this->checkDrainComplete();
        }
    }

    private function finish(ResponseInterface $response, ?Response $target, RequestLifecycle $run): ?ResponseInterface
    {
        $response = $this->normalizeResponseForMethod($response, $run);

        if ($target === null) {
            $run->complete();
            return $response;
        }

        try {
            $this->responseWriter->write($response, $target, $run);
            $run->complete();
        } catch (ResponseWriteFailure $e) {
            $run->fail($e);
            $this->app->trace()->log(TraceType::Failed, 'response', [
                'path' => $run->path,
                'state' => $run->state->value,
                'error' => $e->getMessage(),
                'method' => $run->method,
            ]);

            if ($target->isWritable()) {
                $target->close();
            }
        }

        return null;
    }

    private function normalizeResponseForMethod(ResponseInterface $response, RequestLifecycle $run): ResponseInterface
    {
        if ($run->method !== 'HEAD') {
            return $response;
        }

        return $response->withBody(Utils::streamFor(''));
    }

    private function registerRun(RequestLifecycle $run): void
    {
        $this->activeRuns[$run->id] = $run;

        if ($run->fd !== null) {
            $this->activeRunsByFd[$run->fd] = $run;
        }
    }

    private function unregisterRun(RequestLifecycle $run): void
    {
        unset($this->activeRuns[$run->id]);

        if ($run->fd !== null) {
            unset($this->activeRunsByFd[$run->fd]);
        }
    }

    private function checkDrainComplete(): void
    {
        if (!$this->draining || $this->activeRuns !== []) {
            return;
        }

        Timer::after(50, function (): void {
            $this->finalize();
        });
    }

    private function finalize(): void
    {
        if (!$this->draining && !$this->running && $this->server === null && !$this->workerStarted) {
            return;
        }

        $this->running = false;
        $this->draining = false;

        if ($this->drainTimer !== null) {
            Timer::clear($this->drainTimer);
            $this->drainTimer = null;
        }

        foreach ($this->activeRuns as $run) {
            $run->abort('server shutdown');
        }
        $this->activeRuns = [];
        $this->activeRunsByFd = [];

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'shutdown');
        $this->app->shutdown();
        $this->workerStarted = false;
        $this->server?->shutdown();
        $this->server = null;
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

    private function errorResponse(Throwable $e, RequestLifecycle $run): ResponseInterface
    {
        $body = [
            'error' => 'Internal Server Error',
        ];

        if ($this->debug) {
            $body['message'] = $e->getMessage();
            $body['request'] = [
                'path' => $run->path,
                'state' => $run->state->value,
                'method' => $run->method,
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
