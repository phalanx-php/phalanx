<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\AppHost;
use Phalanx\Boot\AppContext;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\Runtime;
use Phalanx\Stoa\StoaApplication;
use Phalanx\Stoa\StoaRuntimeRunner;
use Phalanx\Stoa\StoaServerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StoaServerConfigTest extends TestCase
{
    #[Test]
    public function buildsFromRuntimeContextWithoutProcessGlobals(): void
    {
        $config = StoaServerConfig::fromContext(new AppContext([
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
        $config = StoaServerConfig::fromContext(new AppContext([
            'PHALANX_DOCUMENT_ROOT' => '/srv/static',
            'PHALANX_ENABLE_STATIC_HANDLER' => 'true',
            'PHALANX_HTTP_COMPRESSION' => 'false',
        ]));

        self::assertSame('/srv/static', $config->documentRoot);
        self::assertTrue($config->enableStaticHandler);
        self::assertFalse($config->httpCompression);
    }

    #[Test]
    public function poweredByHeaderCanBeDisabledFromContext(): void
    {
        $config = StoaServerConfig::fromContext(new AppContext([
            'PHALANX_POWERED_BY' => 'off',
        ]));

        self::assertNull($config->poweredBy);
    }

    #[Test]
    public function phalanxApplicationConfigOverridesRuntimeFallback(): void
    {
        $host = $this->createStub(AppHost::class);
        $runtime = new StoaServerConfig(host: '0.0.0.0', port: 8080);
        $explicit = new StoaServerConfig(host: '127.0.0.2', port: 8181);
        $application = new StoaApplication($host, RouteGroup::of([]), $explicit);

        self::assertSame($explicit, $application->serverConfig($runtime));
    }

    #[Test]
    public function runtimeFallbackIsUsedWhenApplicationHasNoServerConfig(): void
    {
        $host = $this->createStub(AppHost::class);
        $runtime = new StoaServerConfig(host: '127.0.0.3', port: 8282);
        $application = new StoaApplication($host, RouteGroup::of([]));

        self::assertSame($runtime, $application->serverConfig($runtime));
    }

    #[Test]
    public function symfonyRuntimeUsesStoaApplicationRunner(): void
    {
        if (!class_exists(\Symfony\Component\Runtime\GenericRuntime::class)) {
            self::markTestSkipped('symfony/runtime is not installed.');
        }

        $host = $this->createStub(AppHost::class);
        $application = new StoaApplication($host, RouteGroup::of([]));

        try {
            $runner = (new Runtime())->getRunner($application);
        } finally {
            restore_error_handler();
        }

        self::assertInstanceOf(StoaRuntimeRunner::class, $runner);
    }

    #[Test]
    public function symfonyRuntimeRejectsBareAppHost(): void
    {
        if (!class_exists(\Symfony\Component\Runtime\GenericRuntime::class)) {
            self::markTestSkipped('symfony/runtime is not installed.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stoa runtime expects a StoaApplication');

        try {
            (new Runtime())->getRunner($this->createStub(AppHost::class));
        } finally {
            restore_error_handler();
        }
    }
}
