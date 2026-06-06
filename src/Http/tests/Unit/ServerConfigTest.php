<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Unit;

use Phalanx\AppHost;
use Phalanx\Boot\AppContext;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Runtime;
use Phalanx\Http\Application;
use Phalanx\Http\RuntimeRunner;
use Phalanx\Http\ServerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HttpServerConfigTest extends TestCase
{
    #[Test]
    public function buildsFromRuntimeContextWithoutProcessGlobals(): void
    {
        $config = \Phalanx\Http\ServerConfig::fromContext(new AppContext([
            'PHALANX_HOST' => '127.0.0.1',
            'PHALANX_PORT' => '9090',
            'PHALANX_REQUEST_TIMEOUT' => '2.5',
            'PHALANX_DRAIN_TIMEOUT' => '4.5',
            'PHALANX_IGNITION_ENABLED' => 'true',
            'PHALANX_QUIET' => 'true',
            'PHALANX_POWERED_BY' => 'Custom Runtime',
        ]));

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame(2.5, $config->requestTimeout);
        self::assertSame(4.5, $config->drainTimeout);
        self::assertTrue($config->ignitionEnabled);
        self::assertTrue($config->quiet);
        self::assertSame('Custom Runtime', $config->poweredBy);
        self::assertNull($config->documentRoot);
        self::assertFalse($config->enableStaticHandler);
        self::assertTrue($config->httpCompression);
    }

    #[Test]
    public function staticHandlerAndCompressionFlowFromContext(): void
    {
        $config = \Phalanx\Http\ServerConfig::fromContext(new AppContext([
            'PHALANX_DOCUMENT_ROOT' => '/srv/static',
            'PHALANX_ENABLE_STATIC_HANDLER' => 'true',
            'PHALANX_HTTP_COMPRESSION' => 'false',
        ]));

        self::assertSame('/srv/static', $config->documentRoot);
        self::assertTrue($config->enableStaticHandler);
        self::assertFalse($config->httpCompression);
    }

    #[Test]
    public function errorPageAssetsFlowFromContext(): void
    {
        $config = \Phalanx\Http\ServerConfig::fromContext(new AppContext([
            'PHALANX_DOCS_URL' => '/docs',
            'PHALANX_GITHUB_URL' => '/source',
            'PHALANX_SWOOLE_DOCS_URL' => '/swoole',
            'PHALANX_PHP_DOCS_URL' => '/php',
            'PHALANX_PHP_LOGO_URL' => '/assets/php.svg',
            'PHALANX_SWOOLE_LOGO_URL' => '/assets/swoole.png',
            'PHALANX_MARK_URL' => '/assets/mark.png',
            'PHALANX_LUCIDE_SCRIPT_URL' => '/assets/lucide.js',
            'PHALANX_FONT_STYLESHEET_URL' => '/assets/fonts.css',
            'PHALANX_FONT_PRECONNECT_URL' => '/assets/fonts',
            'PHALANX_FONT_STATIC_PRECONNECT_URL' => '/assets/fonts-static',
            'PHALANX_PRISM_THEME_STYLESHEET_URL' => '/assets/prism.css',
            'PHALANX_PRISM_LINE_NUMBERS_STYLESHEET_URL' => '/assets/prism-line-numbers.css',
            'PHALANX_PRISM_LINE_HIGHLIGHT_STYLESHEET_URL' => '/assets/prism-line-highlight.css',
            'PHALANX_PRISM_SCRIPT_URL' => '/assets/prism.js',
            'PHALANX_PRISM_PHP_SCRIPT_URL' => '/assets/prism-php.js',
            'PHALANX_PRISM_LINE_NUMBERS_SCRIPT_URL' => '/assets/prism-line-numbers.js',
            'PHALANX_PRISM_LINE_HIGHLIGHT_SCRIPT_URL' => '/assets/prism-line-highlight.js',
        ]));

        self::assertSame('/docs', $config->docsUrl);
        self::assertSame('/source', $config->githubUrl);
        self::assertSame('/swoole', $config->swooleDocsUrl);
        self::assertSame('/php', $config->phpDocsUrl);
        self::assertSame('/assets/php.svg', $config->phpLogoUrl);
        self::assertSame('/assets/swoole.png', $config->swooleLogoUrl);
        self::assertSame('/assets/mark.png', $config->phalanxMarkUrl);
        self::assertSame('/assets/lucide.js', $config->lucideScriptUrl);
        self::assertSame('/assets/fonts.css', $config->fontStylesheetUrl);
        self::assertSame('/assets/fonts', $config->fontPreconnectUrl);
        self::assertSame('/assets/fonts-static', $config->fontStaticPreconnectUrl);
        self::assertSame('/assets/prism.css', $config->prismThemeStylesheetUrl);
        self::assertSame('/assets/prism-line-numbers.css', $config->prismLineNumbersStylesheetUrl);
        self::assertSame('/assets/prism-line-highlight.css', $config->prismLineHighlightStylesheetUrl);
        self::assertSame('/assets/prism.js', $config->prismScriptUrl);
        self::assertSame('/assets/prism-php.js', $config->prismPhpScriptUrl);
        self::assertSame('/assets/prism-line-numbers.js', $config->prismLineNumbersScriptUrl);
        self::assertSame('/assets/prism-line-highlight.js', $config->prismLineHighlightScriptUrl);
    }

    #[Test]
    public function poweredByHeaderCanBeDisabledFromContext(): void
    {
        $config = \Phalanx\Http\ServerConfig::fromContext(new AppContext([
            'PHALANX_POWERED_BY' => 'off',
        ]));

        self::assertNull($config->poweredBy);
    }

    #[Test]
    public function phalanxApplicationConfigOverridesRuntimeFallback(): void
    {
        $host = $this->createStub(AppHost::class);
        $runtime = new \Phalanx\Http\ServerConfig(host: '0.0.0.0', port: 8080);
        $explicit = new \Phalanx\Http\ServerConfig(host: '127.0.0.2', port: 8181);
        $application = new \Phalanx\Http\Application($host, RouteGroup::of([]), $explicit);

        self::assertSame($explicit, $application->serverConfig($runtime));
    }

    #[Test]
    public function runtimeFallbackIsUsedWhenApplicationHasNoServerConfig(): void
    {
        $host = $this->createStub(AppHost::class);
        $runtime = new \Phalanx\Http\ServerConfig(host: '127.0.0.3', port: 8282);
        $application = new \Phalanx\Http\Application($host, RouteGroup::of([]));

        self::assertSame($runtime, $application->serverConfig($runtime));
    }

    #[Test]
    public function symfonyRuntimeUsesHttpApplicationRunner(): void
    {
        if (!class_exists(\Symfony\Component\Runtime\GenericRuntime::class)) {
            self::markTestSkipped('symfony/runtime is not installed.');
        }

        $host = $this->createStub(AppHost::class);
        $application = new \Phalanx\Http\Application($host, RouteGroup::of([]));

        try {
            $runner = new Runtime()->getRunner($application);
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(\Phalanx\Http\RuntimeRunner::class, $runner);
    }

    #[Test]
    public function bannerDefaultsToNull(): void
    {
        self::assertNull(\Phalanx\Http\ServerConfig::defaults()->banner);
        self::assertNull(\Phalanx\Http\ServerConfig::fromContext(new AppContext())->banner);
    }

    #[Test]
    public function bannerIsPreservedThroughConstructor(): void
    {
        $config = new \Phalanx\Http\ServerConfig(banner: 'Test banner {url}');

        self::assertSame('Test banner {url}', $config->banner);
    }

    #[Test]
    public function symfonyRuntimeRejectsBareAppHost(): void
    {
        if (!class_exists(\Symfony\Component\Runtime\GenericRuntime::class)) {
            self::markTestSkipped('symfony/runtime is not installed.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP runtime expects a Phalanx\\Http\\Application');

        try {
            new Runtime()->getRunner($this->createStub(AppHost::class));
        } finally {
            restore_error_handler();
        }
    }
}
