<?php

declare(strict_types=1);

namespace Phalanx\Http\Tests\Integration;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Http\ServerConfig;
use Phalanx\Http\RequestContext;
use Phalanx\Http\RouteGroup;
use Phalanx\Http\Tests\Support\TestCase;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Task\Scopeable;
use PHPUnit\Framework\Attributes\Test;

final class ApplicationBuilderTest extends TestCase
{
    #[Test]
    public function buildsDispatchableApplicationWithServerConfigAndDefaultPoweredByHeader(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $app = self::http([
                'PHALANX_REQUEST_TIMEOUT' => '2.5',
            ])
                ->routes([
                    'GET /hello' => BuilderHelloRoute::class,
                ])
                ->listen('127.0.0.1:9099')
                ->drainTimeout(4.5)
                ->build();

            try {
                $response = $app->dispatch(new ServerRequest('GET', '/hello'));
            } finally {
                $app->shutdown();
            }

            self::assertSame('hello', (string) $response->getBody());
            self::assertSame('Phalanx', $response->getHeaderLine('X-Powered-By'));
            self::assertSame('127.0.0.1', $app->serverConfig()->host);
            self::assertSame(9099, $app->serverConfig()->port);
            self::assertSame(2.5, $app->serverConfig()->requestTimeout);
            self::assertSame(4.5, $app->serverConfig()->drainTimeout);
        });
    }

    #[Test]
    public function leavesServerConfigToRuntimeFallbackWhenNoServerConfigWasDeclared(): void
    {
        $app = self::http()
            ->routes([
                'GET /hello' => BuilderHelloRoute::class,
            ])
            ->build();
        $runtime = new \Phalanx\Http\ServerConfig(host: '127.0.0.9', port: 9099);

        self::assertSame($runtime, $app->serverConfig($runtime));
    }

    #[Test]
    public function canDisableOrOverridePoweredByWithoutOverwritingHandlerHeader(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $custom = self::http()
                ->routes(['GET /hello' => BuilderHelloRoute::class])
                ->withServerConfig(new \Phalanx\Http\ServerConfig(poweredBy: 'Custom'))
                ->build();
            $disabled = self::http()
                ->routes(['GET /hello' => BuilderHelloRoute::class])
                ->withServerConfig(new \Phalanx\Http\ServerConfig(poweredBy: null))
                ->build();
            $explicit = self::http()
                ->routes(['GET /explicit' => BuilderExplicitPoweredByRoute::class])
                ->withServerConfig(new \Phalanx\Http\ServerConfig(poweredBy: 'Custom'))
                ->build();

            try {
                self::assertSame(
                    'Custom',
                    $custom->dispatch(new ServerRequest('GET', '/hello'))->getHeaderLine('X-Powered-By'),
                );
                self::assertSame(
                    '',
                    $disabled->dispatch(new ServerRequest('GET', '/hello'))->getHeaderLine('X-Powered-By'),
                );
                self::assertSame(
                    'Handler',
                    $explicit->dispatch(new ServerRequest('GET', '/explicit'))->getHeaderLine('X-Powered-By'),
                );
            } finally {
                $custom->shutdown();
                $disabled->shutdown();
                $explicit->shutdown();
            }
        });
    }

    #[Test]
    public function loadsRoutesFromAFileOrDirectory(): void
    {
        $this->scope->run(static function (ExecutionScope $_scope): void {
            $dir = sys_get_temp_dir() . '/' . uniqid('http-routes-', true);
            mkdir($dir);

            $file = $dir . '/routes.php';
            file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

use Phalanx\Http\RouteGroup;
use Phalanx\Http\Tests\Integration\BuilderLoadedRoute;

return RouteGroup::of([
    'GET /loaded' => BuilderLoadedRoute::class,
]);
PHP);

            $fromFile = self::http()->routes($file)->build();
            $fromDir = self::http()->routes($dir)->build();

            try {
                self::assertSame(
                    'loaded',
                    (string) $fromFile->dispatch(new ServerRequest('GET', '/loaded'))->getBody(),
                );
                self::assertSame(
                    'loaded',
                    (string) $fromDir->dispatch(new ServerRequest('GET', '/loaded'))->getBody(),
                );
            } finally {
                $fromFile->shutdown();
                $fromDir->shutdown();
                unlink($file);
                rmdir($dir);
            }
        });
    }

    #[Test]
    public function bannerFlowsThroughBuilderToServerConfig(): void
    {
        $banner = "Test Server\nListening on {url}";

        $app = self::http()
            ->routes(['GET /hello' => BuilderHelloRoute::class])
            ->listen('127.0.0.1:9099')
            ->withBanner($banner)
            ->build();

        try {
            self::assertSame($banner, $app->serverConfig()->banner);
        } finally {
            $app->shutdown();
        }
    }

    #[Test]
    public function bannerIsNullByDefault(): void
    {
        $app = self::http()
            ->routes(['GET /hello' => BuilderHelloRoute::class])
            ->listen('127.0.0.1:9099')
            ->build();

        try {
            self::assertNull($app->serverConfig()->banner);
        } finally {
            $app->shutdown();
        }
    }

}

final class BuilderHelloRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): string
    {
        return 'hello';
    }
}

final class BuilderExplicitPoweredByRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): Response
    {
        return new Response(200, ['X-Powered-By' => 'Handler'], 'explicit');
    }
}

final class BuilderLoadedRoute implements Scopeable
{
    public function __invoke(RequestContext $ctx): string
    {
        return 'loaded';
    }
}
