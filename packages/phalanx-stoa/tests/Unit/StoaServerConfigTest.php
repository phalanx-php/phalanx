<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\AppHost;
use Phalanx\Stoa\Runtime;
use Phalanx\Stoa\RouteGroup;
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
        $config = StoaServerConfig::fromContext([
            'PHALANX_HOST' => '127.0.0.1',
            'PHALANX_PORT' => '9090',
            'PHALANX_REQUEST_TIMEOUT' => '2.5',
            'PHALANX_DRAIN_TIMEOUT' => '4.5',
            'PHALANX_DEBUG' => 'true',
            'PHALANX_QUIET' => 'true',
            'PHALANX_POWERED_BY' => 'Custom Runtime',
        ]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame(2.5, $config->requestTimeout);
        self::assertSame(4.5, $config->drainTimeout);
        self::assertTrue($config->debug);
        self::assertTrue($config->quiet);
        self::assertSame('Custom Runtime', $config->poweredBy);
    }

    #[Test]
    public function poweredByHeaderCanBeDisabledFromContext(): void
    {
        $config = StoaServerConfig::fromContext([
            'PHALANX_POWERED_BY' => 'off',
        ]);

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

        $runner = (new Runtime())->getRunner($application);

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

        (new Runtime())->getRunner($this->createStub(AppHost::class));
    }
}
