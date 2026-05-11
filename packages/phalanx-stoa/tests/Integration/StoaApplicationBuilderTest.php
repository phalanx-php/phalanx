<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Integration;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Stoa;
use Phalanx\Stoa\StoaApplicationBuilder;
use Phalanx\Stoa\StoaServerConfig;
use Phalanx\Supervisor\InProcessLedger;
use Phalanx\Task\Scopeable;
use Phalanx\Tests\Support\CoroutineTestCase;
use PHPUnit\Framework\Attributes\Test;

final class StoaApplicationBuilderTest extends CoroutineTestCase
{
    #[Test]
    public function buildsDispatchableApplicationWithServerConfigAndDefaultPoweredByHeader(): void
    {
        $this->runInCoroutine(static function (): void {
            $app = self::stoa([
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
        $app = self::stoa()
            ->routes([
                'GET /hello' => BuilderHelloRoute::class,
            ])
            ->build();
        $runtime = new \Phalanx\Stoa\StoaServerConfig(host: '127.0.0.9', port: 9099);

        self::assertSame($runtime, $app->serverConfig($runtime));
    }

    #[Test]
    public function canDisableOrOverridePoweredByWithoutOverwritingHandlerHeader(): void
    {
        $this->runInCoroutine(static function (): void {
            $custom = self::stoa()
                ->routes(['GET /hello' => BuilderHelloRoute::class])
                ->withServerConfig(new StoaServerConfig(poweredBy: 'Custom'))
                ->build();
            $disabled = self::stoa()
                ->routes(['GET /hello' => BuilderHelloRoute::class])
                ->withServerConfig(new StoaServerConfig(poweredBy: null))
                ->build();
            $explicit = self::stoa()
                ->routes(['GET /explicit' => BuilderExplicitPoweredByRoute::class])
                ->withServerConfig(new StoaServerConfig(poweredBy: 'Custom'))
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
        $this->runInCoroutine(static function (): void {
            $dir = sys_get_temp_dir() . '/' . uniqid('stoa-routes-', true);
            mkdir($dir);

            $file = $dir . '/routes.php';
            file_put_contents($file, <<<'PHP'
<?php

declare(strict_types=1);

use Phalanx\Stoa\RouteGroup;
use Phalanx\Tests\Stoa\Integration\BuilderLoadedRoute;

return RouteGroup::of([
    'GET /loaded' => BuilderLoadedRoute::class,
]);
PHP);

            $fromFile = self::stoa()->routes($file)->build();
            $fromDir = self::stoa()->routes($dir)->build();

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

    /** @param array<string, mixed> $context */
    private static function stoa(array $context = []): StoaApplicationBuilder
    {
        return Stoa::starting($context)->withLedger(new InProcessLedger());
    }
}

final class BuilderHelloRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        return 'hello';
    }
}

final class BuilderExplicitPoweredByRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): Response
    {
        return new Response(200, ['X-Powered-By' => 'Handler'], 'explicit');
    }
}

final class BuilderLoadedRoute implements Scopeable
{
    public function __invoke(RequestScope $scope): string
    {
        return 'loaded';
    }
}
