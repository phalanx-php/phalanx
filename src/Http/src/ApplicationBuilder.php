<?php

declare(strict_types=1);

namespace Phalanx\Http;

use InvalidArgumentException;
use Phalanx\AppHost;
use Phalanx\Application;
use Phalanx\ApplicationBuilder as RuntimeApplicationBuilder;
use Phalanx\Boot\AppContext;
use Phalanx\Middleware\ServiceTransformationMiddleware;
use Phalanx\Middleware\TaskMiddleware;
use Phalanx\Runtime\RuntimePolicy;
use Phalanx\Service\ServiceBundle;
use Phalanx\Supervisor\LedgerStorage;
use Phalanx\Trace\Trace;
use Phalanx\Worker\WorkerDispatch;

/**
 * Module entry builder for Http applications.
 *
 * Bootstrap files should enter through `Server::starting($context)`, not
 * through the root Runtime ApplicationBuilder plus a manually assembled runner.
 */
final class ApplicationBuilder
{
    private readonly RuntimeApplicationBuilder $app;

    /** @var list<RouteGroup|string|list<string>|array<string, class-string>> */
    private array $routeSources = [];

    /** @var list<Response\ErrorResponseRenderer> */
    private array $errorRenderers = [];

    private ?string $host = null;

    private ?int $port = null;

    private ?float $requestTimeout = null;

    private ?float $drainTimeout = null;

    private ?bool $ignitionEnabled = null;

    private ?bool $quiet = null;

    private ?\Phalanx\Http\ServerConfig $serverConfig = null;

    private ?string $banner = null;

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

    public function withErrorRenderers(Response\ErrorResponseRenderer ...$renderers): self
    {
        $this->errorRenderers = array_values([...$this->errorRenderers, ...$renderers]);

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

    public function withBanner(string $banner): self
    {
        $this->banner = $banner;

        return $this;
    }

    public function withServerConfig(\Phalanx\Http\ServerConfig $config): self
    {
        $this->serverConfig = $config;

        return $this;
    }

    public function build(): \Phalanx\Http\Application
    {
        $host = $this->app->compile();
        $routes = RouteGroup::of([]);

        foreach ($this->routeSources as $source) {
            $routes = $routes->merge(self::loadRoutes($host, $source));
        }

        return new \Phalanx\Http\Application(
            host: $host,
            routes: $routes,
            serverConfig: $this->hasServerConfigInput() ? $this->resolveServerConfig() : null,
            errorRenderers: $this->errorRenderers,
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

    private function resolveServerConfig(): \Phalanx\Http\ServerConfig
    {
        $base = $this->serverConfig ?? \Phalanx\Http\ServerConfig::fromContext($this->context);

        return new \Phalanx\Http\ServerConfig(
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
            swooleDocsUrl: $base->swooleDocsUrl,
            phpDocsUrl: $base->phpDocsUrl,
            phpLogoUrl: $base->phpLogoUrl,
            swooleLogoUrl: $base->swooleLogoUrl,
            phalanxMarkUrl: $base->phalanxMarkUrl,
            lucideScriptUrl: $base->lucideScriptUrl,
            fontStylesheetUrl: $base->fontStylesheetUrl,
            fontPreconnectUrl: $base->fontPreconnectUrl,
            fontStaticPreconnectUrl: $base->fontStaticPreconnectUrl,
            prismThemeStylesheetUrl: $base->prismThemeStylesheetUrl,
            prismLineNumbersStylesheetUrl: $base->prismLineNumbersStylesheetUrl,
            prismLineHighlightStylesheetUrl: $base->prismLineHighlightStylesheetUrl,
            prismScriptUrl: $base->prismScriptUrl,
            prismPhpScriptUrl: $base->prismPhpScriptUrl,
            prismLineNumbersScriptUrl: $base->prismLineNumbersScriptUrl,
            prismLineHighlightScriptUrl: $base->prismLineHighlightScriptUrl,
            banner: $this->banner ?? $base->banner,
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
            || $this->banner !== null
        ) {
            return true;
        }

        return array_any([
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
            'docs_url',
            'github_url',
            'swoole_docs_url',
            'php_docs_url',
            'php_logo_url',
            'swoole_logo_url',
            'phalanx_mark_url',
            'lucide_script_url',
            'font_stylesheet_url',
            'font_preconnect_url',
            'font_static_preconnect_url',
            'prism_theme_stylesheet_url',
            'prism_line_numbers_stylesheet_url',
            'prism_line_highlight_stylesheet_url',
            'prism_script_url',
            'prism_php_script_url',
            'prism_line_numbers_script_url',
            'prism_line_highlight_script_url',
            'PHALANX_DOCS_URL',
            'PHALANX_GITHUB_URL',
            'PHALANX_SWOOLE_DOCS_URL',
            'PHALANX_PHP_DOCS_URL',
            'PHALANX_PHP_LOGO_URL',
            'PHALANX_SWOOLE_LOGO_URL',
            'PHALANX_MARK_URL',
            'PHALANX_LUCIDE_SCRIPT_URL',
            'PHALANX_FONT_STYLESHEET_URL',
            'PHALANX_FONT_PRECONNECT_URL',
            'PHALANX_FONT_STATIC_PRECONNECT_URL',
            'PHALANX_PRISM_THEME_STYLESHEET_URL',
            'PHALANX_PRISM_LINE_NUMBERS_STYLESHEET_URL',
            'PHALANX_PRISM_LINE_HIGHLIGHT_STYLESHEET_URL',
            'PHALANX_PRISM_SCRIPT_URL',
            'PHALANX_PRISM_PHP_SCRIPT_URL',
            'PHALANX_PRISM_LINE_NUMBERS_SCRIPT_URL',
            'PHALANX_PRISM_LINE_HIGHLIGHT_SCRIPT_URL',
        ], fn($key) => $this->context->has($key));
    }
}
