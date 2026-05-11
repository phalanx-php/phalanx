<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use Phalanx\AppHost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class StoaApplication
{
    private ?StoaRunner $runner = null;

    private bool $started = false;

    public function __construct(
        private readonly AppHost $host,
        private readonly RouteGroup $routes,
        private readonly ?StoaServerConfig $serverConfig = null,
    ) {
    }

    public function aegis(): AppHost
    {
        return $this->host;
    }

    public function host(): AppHost
    {
        return $this->host;
    }

    public function routes(): RouteGroup
    {
        return $this->routes;
    }

    public function ignitionEnabled(): bool
    {
        return $this->serverConfig()->ignitionEnabled;
    }

    public function serverConfig(?StoaServerConfig $fallback = null): StoaServerConfig
    {
        return $this->serverConfig ?? $fallback ?? StoaServerConfig::defaults();
    }

    public function startup(): self
    {
        if (!$this->started) {
            $this->host->startup();
            $this->started = true;
        }

        return $this;
    }

    public function shutdown(): void
    {
        if (!$this->started) {
            return;
        }

        $this->host->shutdown();
        $this->started = false;
    }

    public function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $this->startup();

        return $this->runner()->dispatch($request);
    }

    public function run(?string $listen = null, ?StoaServerConfig $fallback = null): int
    {
        $config = $this->serverConfig($fallback);

        return $this->runner($fallback)->run(
            $listen ?? "{$config->host}:{$config->port}",
        );
    }

    public function activeRequests(): int
    {
        return $this->runner?->activeRequests() ?? 0;
    }

    private function runner(?StoaServerConfig $fallback = null): StoaRunner
    {
        if ($this->runner !== null) {
            return $this->runner;
        }

        $config = $this->serverConfig($fallback);

        $this->runner = StoaRunner::from($this->host, $config)
            ->withRoutes($this->routes);

        return $this->runner;
    }
}
