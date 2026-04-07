<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\AppHost;
use Phalanx\Concurrency\CancellationToken;
use Phalanx\Support\SignalHandler;
use Phalanx\Trace\TraceType;
use Phalanx\WebSocket\WsRouteGroup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Datagram\Factory as DatagramFactory;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use function React\Async\async;

final class Runner
{
    private bool $running = false;
    private bool $shutdownRequested = false;

    private ?HttpServer $server = null;
    private ?SocketServer $socket = null;
    private ?TimerInterface $windowsTimer = null;

    private ?RouteGroup $routes = null;

    /** @var list<WsRouteGroup> */
    private array $wsGroups = [];

    /** @var list<array{handler: callable, port: int, config: UdpConfig}> */
    private array $udpListeners = [];

    /** @var list<\React\Datagram\Socket> */
    private array $udpSockets = [];

    /** @var ?callable(\Phalanx\ExecutionScope, \React\Stream\DuplexStreamInterface, \Psr\Http\Message\ServerRequestInterface): ?\Psr\Http\Message\ResponseInterface */
    private $onUpgrade = null;

    private function __construct(
        private readonly AppHost $app,
        private readonly float $requestTimeout = 30.0,
        private readonly bool $debug = false,
    ) {}

    public static function from(AppHost $app, float $requestTimeout = 30.0, bool $debug = false): self
    {
        return new self($app, $requestTimeout, $debug);
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

    public function withWebsockets(WsRouteGroup $wsRoutes): self
    {
        $this->wsGroups[] = $wsRoutes;
        return $this;
    }

    public function withUdp(
        callable $handler,
        int $port = 8081,
        UdpConfig $config = new UdpConfig(),
    ): self {
        $this->udpListeners[] = [
            'handler' => $handler,
            'port' => $port,
            'config' => $config,
        ];

        return $this;
    }

    public function withUpgradeHandler(callable $onUpgrade): self
    {
        $this->onUpgrade = $onUpgrade;
        return $this;
    }

    public function run(?string $listen = '0.0.0.0:8080'): int
    {
        if ($this->routes === null && $this->wsGroups === [] && $this->udpListeners === [] && $this->onUpgrade === null) {
            throw new \RuntimeException('No handlers configured. Call withRoutes(), withWebsockets(), or withUdp() before run().');
        }

        $this->app->startup();

        $needsHttp = $this->routes !== null || $this->wsGroups !== [] || $this->onUpgrade !== null;

        if ($needsHttp) {
            if ($listen === null) {
                throw new \RuntimeException('HTTP listen address required when HTTP routes or WebSocket routes are configured.');
            }
            $this->startHttp($listen);
        }

        foreach ($this->udpListeners as $listener) {
            $this->startUdp($listener);
        }

        $this->running = true;
        $this->setupSignalHandlers();
        $this->setupWindowsShutdownCheck();

        Loop::run();

        return 0;
    }

    public function stop(): void
    {
        $this->shutdown();
    }

    private function startHttp(string $listen): void
    {
        $this->socket = new SocketServer($listen);
        $this->server = new HttpServer($this->handleRequest(...));
        $this->server->listen($this->socket);

        $this->app->trace()->log(TraceType::LifecycleStartup, 'ready', ['uri' => $listen]);
        printf("Server running at http://%s\n", $listen);
    }

    /** @param array{handler: callable, port: int, config: UdpConfig} $listener */
    private function startUdp(array $listener): void
    {
        $factory = new DatagramFactory();
        $factory->createServer("0.0.0.0:{$listener['port']}")
            ->then(function (\React\Datagram\Socket $socket) use ($listener): void {
                $this->udpSockets[] = $socket;

                $socket->on('message', function (string $data, string $remote) use ($listener): void {
                    if (strlen($data) > $listener['config']->maxPayloadSize) {
                        return;
                    }

                    $scope = $this->app->createScope(CancellationToken::none());

                    try {
                        ($listener['handler'])($data, $remote, $scope);
                    } catch (\Throwable $e) {
                        $this->app->trace()->log(TraceType::Failed, 'udp', [
                            'error' => $e->getMessage(),
                            'remote' => $remote,
                        ]);
                    } finally {
                        $scope->dispose();
                    }
                });

                $this->app->trace()->log(TraceType::LifecycleStartup, 'udp', ['port' => $listener['port']]);
                printf("UDP listening on port %d\n", $listener['port']);
            });
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->isWebSocketUpgrade($request)) {
            return $this->handleUpgrade($request);
        }

        if ($this->routes === null) {
            return Response::json(['error' => 'Not Found'])->withStatus(404);
        }

        $token = CancellationToken::timeout($this->requestTimeout);
        $scope = $this->app->createScope($token);
        $scope = $scope->withAttribute('request', $request);
        $trace = $scope->trace();
        $trace->reset();

        try {
            $response = $scope->execute($this->routes);

            if ($response instanceof ResponseInterface) {
                return $response;
            }

            return self::toResponse($response);
        } catch (\Throwable $e) {
            if ($e instanceof ToResponse) {
                return $e->toResponse();
            }

            $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);

            $body = ['error' => 'Internal Server Error'];
            if ($this->debug) {
                $body['message'] = $e->getMessage();
                $body['trace'] = $this->formatTrace($e);
            }

            return Response::json($body)->withStatus(500);
        } finally {
            $trace->print();
            $scope->dispose();
        }
    }

    private function handleUpgrade(ServerRequestInterface $request): ResponseInterface
    {
        $scope = $this->app->createScope(CancellationToken::none());
        $scope = $scope->withAttribute('request', $request);

        try {
            $outgoing = new ThroughStream();
            $incoming = new ThroughStream();
            $transport = new CompositeStream($incoming, $outgoing);
            $body = new CompositeStream($outgoing, $incoming);

            foreach ($this->wsGroups as $wsGroup) {
                try {
                    $handler = $wsGroup->upgradeHandler();
                    $response = $handler($scope, $transport, $request);

                    if ($response instanceof ResponseInterface && $response->getStatusCode() === 101) {
                        return new Response(101, $response->getHeaders(), $body);
                    }
                } catch (RouteNotFoundException) {
                    continue;
                }
            }

            if ($this->onUpgrade !== null) {
                $response = ($this->onUpgrade)($scope, $transport, $request);
                if ($response instanceof ResponseInterface && $response->getStatusCode() === 101) {
                    return new Response(101, $response->getHeaders(), $body);
                }
            }

            $scope->dispose();
            return Response::json(['error' => 'No WebSocket route matches'])->withStatus(404);
        } catch (\Throwable $e) {
            $scope->dispose();
            return Response::json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    private function isWebSocketUpgrade(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('Upgrade')) === 'websocket'
            && stripos($request->getHeaderLine('Connection'), 'upgrade') !== false;
    }

    private function setupSignalHandlers(): void
    {
        SignalHandler::register($this->createShutdownHandler());
    }

    private function setupWindowsShutdownCheck(): void
    {
        if (!SignalHandler::isWindows()) {
            return;
        }

        $shutdownRequested = &$this->shutdownRequested;
        $this->windowsTimer = Loop::addPeriodicTimer(0.1, static function () use (&$shutdownRequested): void {
            if ($shutdownRequested) {
                Loop::stop();
            }
        });
    }

    private function createShutdownHandler(): callable
    {
        $shutdown = $this->shutdown(...);

        return static function () use ($shutdown): void {
            $shutdown();
        };
    }

    private function shutdown(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        $this->shutdownRequested = true;

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'shutdown');
        echo "\nShutting down...\n";

        if ($this->windowsTimer !== null) {
            Loop::cancelTimer($this->windowsTimer);
            $this->windowsTimer = null;
        }

        foreach ($this->udpSockets as $socket) {
            $socket->close();
        }
        $this->udpSockets = [];

        $this->socket?->close();
        $this->app->shutdown();

        if (!SignalHandler::isWindows()) {
            Loop::stop();
        }
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
            return Response::json($data);
        }

        if (is_string($data)) {
            return new Response(200, ['Content-Type' => 'text/plain'], $data);
        }

        return Response::json(['result' => $data]);
    }

    /** @return list<string> */
    private function formatTrace(\Throwable $e): array
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

    /** @param string|list<string> $paths */
    private static function loadRoutes(AppHost $app, string|array $paths): RouteGroup
    {
        $paths = is_string($paths) ? [$paths] : $paths;
        $scope = $app->createScope();
        $group = RouteGroup::create();

        foreach ($paths as $dir) {
            $group = $group->merge(RouteLoader::loadDirectory($dir, $scope));
        }

        return $group;
    }
}
