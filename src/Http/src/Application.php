<?php

declare(strict_types=1);

namespace Phalanx\Http;

use Phalanx\AppHost;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Application
{
    private ?\Phalanx\Http\Runner $runner = null;

    private bool $started = false;

    /**
     * @param list<Response\ErrorResponseRenderer> $errorRenderers
     */
    public function __construct(
        private readonly AppHost $host,
        private readonly RouteGroup $routes,
        private readonly ?\Phalanx\Http\ServerConfig $serverConfig = null,
        private readonly array $errorRenderers = [],
    ) {
    }

    public function runtime(): AppHost
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

    public function serverConfig(?\Phalanx\Http\ServerConfig $fallback = null): \Phalanx\Http\ServerConfig
    {
        return $this->serverConfig ?? $fallback ?? \Phalanx\Http\ServerConfig::defaults();
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

    public function run(?string $listen = null, ?\Phalanx\Http\ServerConfig $fallback = null): int
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

    private function runner(?\Phalanx\Http\ServerConfig $fallback = null): \Phalanx\Http\Runner
    {
        if ($this->runner !== null) {
            return $this->runner;
        }

        $config = $this->serverConfig($fallback);

        $this->runner = \Phalanx\Http\Runner::from($this->host, $config, errorRenderers: $this->errorRenderers)
            ->withRoutes($this->routes);

        return $this->runner;
    }
}
