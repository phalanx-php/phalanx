<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

use InvalidArgumentException;
use Phalanx\AppHost;
use Phalanx\Application;
use Phalanx\ApplicationBuilder;
use Phalanx\Boot\AppContext;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Service\ServiceBundle;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerDispatch;

/**
 * Facade builder for Stoa HTTP applications.
 *
 * Bootstrap files should enter through `Stoa::starting($context)`, not
 * through the root Aegis ApplicationBuilder plus a manually assembled runner.
 */
final class StoaApplicationBuilder
{
    private ApplicationBuilder $app;

    /** @var list<RouteGroup|string|list<string>|array<string, class-string>> */
    private array $routeSources = [];

    private ?string $host = null;

    private ?int $port = null;

    private ?float $requestTimeout = null;

    private ?float $drainTimeout = null;

    private ?bool $ignitionEnabled = null;

    private ?bool $quiet = null;

    private ?StoaServerConfig $serverConfig = null;

    public function __construct(private readonly AppContext $context = new AppContext())
    {
        $this->app = Application::starting($context->values);
    }

    public function providers(ServiceBundle ...$providers): self
    {
        $this->app->providers(...$providers);
        return $this;
    }

    public function serviceMiddleware(ServiceTransformationMiddleware ...$middlewares): self
    {
        $this->app->serviceMiddleware(...$middlewares);
        return $this;
    }

    public function taskMiddleware(TaskMiddleware ...$middlewares): self
    {
        $this->app->taskMiddleware(...$middlewares);
        return $this;
    }

    public function withTrace(Trace $trace): self
    {
        $this->app->withTrace($trace);
        return $this;
    }

    public function withWorkerDispatch(WorkerDispatch $dispatch): self
    {
        $this->app->withWorkerDispatch($dispatch);
        return $this;
    }

    public function withRuntimePolicy(RuntimePolicy $policy): self
    {
        $this->app->withRuntimePolicy($policy);
        return $this;
    }

    public function withRuntimeHooksStrict(bool $strict): self
    {
        $this->app->withRuntimeHooksStrict($strict);
        return $this;
    }

    public function withLedger(LedgerStorage $ledger): self
    {
        $this->app->withLedger($ledger);
        return $this;
    }

    /** @param RouteGroup|string|list<string>|array<string, class-string> $routes */
    public function http(RouteGroup|string|array $routes): self
    {
        return $this->routes($routes);
    }

    /** @param RouteGroup|string|list<string>|array<string, class-string> $routes */
    public function routes(RouteGroup|string|array $routes): self
    {
        $this->routeSources[] = $routes;
        return $this;
    }

    public function listen(string $listen): self
    {
        [$host, $port] = self::parseListen($listen);

        $this->host = $host;
        $this->port = $port;

        return $this;
    }

    public function requestTimeout(float $seconds): self
    {
        $this->requestTimeout = $seconds;
        return $this;
    }

    public function drainTimeout(float $seconds): self
    {
        $this->drainTimeout = $seconds;
        return $this;
    }

    public function ignition(bool $enabled = true): self
    {
        $this->ignitionEnabled = $enabled;
        return $this;
    }

    public function quiet(bool $quiet = true): self
    {
        $this->quiet = $quiet;
        return $this;
    }

    public function withServerConfig(StoaServerConfig $config): self
    {
        $this->serverConfig = $config;
        return $this;
    }

    public function build(): StoaApplication
    {
        $host = $this->app->compile();
        $routes = RouteGroup::of([]);

        foreach ($this->routeSources as $source) {
            $routes = $routes->merge(self::loadRoutes($host, $source));
        }

        return new StoaApplication(
            host: $host,
            routes: $routes,
            serverConfig: $this->hasServerConfigInput() ? $this->resolveServerConfig() : null,
        );
    }

    public function run(): int
    {
        return $this->build()->run();
    }

    /** @return array{string, int} */
    private static function parseListen(string $listen): array
    {
        $separator = strrpos($listen, ':');

        if ($separator === false) {
            throw new InvalidArgumentException("Invalid listen address: {$listen}");
        }

        $host = substr($listen, 0, $separator);
        $port = (int) substr($listen, $separator + 1);

        if ($host === '' || $port <= 0) {
            throw new InvalidArgumentException("Invalid listen address: {$listen}");
        }

        return [$host, $port];
    }

    /**
     * @param RouteGroup|string|list<string>|array<string, class-string> $source
     */
    private static function loadRoutes(AppHost $app, RouteGroup|string|array $source): RouteGroup
    {
        if ($source instanceof RouteGroup) {
            return $source;
        }

        if (is_string($source)) {
            return self::loadRoutePath($app, $source);
        }

        if (array_is_list($source)) {
            $group = RouteGroup::of([]);

            foreach ($source as $path) {
                $group = $group->merge(self::loadRoutePath($app, $path));
            }

            return $group;
        }

        /** @var array<string, class-string<\Phalanx\Task\Scopeable|\Phalanx\Task\Executable>> $source */
        return RouteGroup::of($source);
    }

    private static function loadRoutePath(AppHost $app, string $path): RouteGroup
    {
        $scope = $app->createScope();

        try {
            if (is_dir($path)) {
                return RouteLoader::loadDirectory($path, $scope);
            }

            return RouteLoader::load($path, $scope);
        } finally {
            $scope->dispose();
        }
    }

    private function resolveServerConfig(): StoaServerConfig
    {
        $base = $this->serverConfig ?? StoaServerConfig::fromContext($this->context);

        return new StoaServerConfig(
            host: $this->host ?? $base->host,
            port: $this->port ?? $base->port,
            requestTimeout: $this->requestTimeout ?? $base->requestTimeout,
            drainTimeout: $this->drainTimeout ?? $base->drainTimeout,
            ignitionEnabled: $this->ignitionEnabled ?? $base->ignitionEnabled,
            quiet: $this->quiet ?? $base->quiet,
            poweredBy: $base->poweredBy,
            documentRoot: $base->documentRoot,
            enableStaticHandler: $base->enableStaticHandler,
            httpCompression: $base->httpCompression,
            logoPath: $base->logoPath,
            faviconPath: $base->faviconPath,
            tagline: $base->tagline,
            docsUrl: $base->docsUrl,
            githubUrl: $base->githubUrl,
            openswooleDocsUrl: $base->openswooleDocsUrl,
        );
    }

    private function hasServerConfigInput(): bool
    {
        if (
            $this->serverConfig !== null
            || $this->host !== null
            || $this->port !== null
            || $this->requestTimeout !== null
            || $this->drainTimeout !== null
            || $this->ignitionEnabled !== null
            || $this->quiet !== null
        ) {
            return true;
        }

        foreach (
            [
            'host',
            'port',
            'ignition_enabled',
            'quiet',
            'PHALANX_HOST',
            'PHALANX_PORT',
            'PHALANX_IGNITION_ENABLED',
            'PHALANX_QUIET',
            'request_timeout',
            'drain_timeout',
            'PHALANX_REQUEST_TIMEOUT',
            'PHALANX_DRAIN_TIMEOUT',
            ] as $key
        ) {
            if ($this->context->has($key)) {
                return true;
            }
        }

        return false;
    }
}
