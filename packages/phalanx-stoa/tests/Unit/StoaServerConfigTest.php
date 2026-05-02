<?php

declare(strict_types=1);

namespace Phalanx\Tests\Stoa\Unit;

use Phalanx\AppHost;
use Phalanx\Stoa\PhalanxApplication;
use Phalanx\Stoa\RouteGroup;
use Phalanx\Stoa\StoaServerConfig;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
        ]);

        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9090, $config->port);
        self::assertSame(2.5, $config->requestTimeout);
        self::assertSame(4.5, $config->drainTimeout);
    }

    #[Test]
    public function phalanxApplicationConfigOverridesRuntimeFallback(): void
    {
        $host = $this->createMock(AppHost::class);
        $runtime = new StoaServerConfig(host: '0.0.0.0', port: 8080);
        $explicit = new StoaServerConfig(host: '127.0.0.2', port: 8181);
        $application = new PhalanxApplication($host, RouteGroup::of([]), $explicit);

        self::assertSame($explicit, $application->serverConfig($runtime));
    }

    #[Test]
    public function runtimeFallbackIsUsedWhenApplicationHasNoServerConfig(): void
    {
        $host = $this->createMock(AppHost::class);
        $runtime = new StoaServerConfig(host: '127.0.0.3', port: 8282);
        $application = new PhalanxApplication($host, RouteGroup::of([]));

        self::assertSame($runtime, $application->serverConfig($runtime));
    }
}
