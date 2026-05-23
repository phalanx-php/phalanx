<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit;

use Phalanx\Athena\Athena;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\AthenaConfig;
use Phalanx\Athena\Router\SingleProviderRouter;
use Phalanx\Athena\Tool\ToolBundle;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Provider;
use Phalanx\Panoply\Runtime;
use Phalanx\Panoply\Stream;
use Phalanx\Service\ServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AthenaBundleTest extends TestCase
{
    #[Test]
    public function bundleExtendsServiceBundle(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $bundle = new AthenaBundle($router);

        self::assertInstanceOf(ServiceBundle::class, $bundle);
    }

    #[Test]
    public function facadeServicesReturnsBundle(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $bundle = Athena::services($router);

        self::assertInstanceOf(AthenaBundle::class, $bundle);
    }

    #[Test]
    public function facadeServicesAcceptsToolBundles(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $tools = new ToolBundle();
        $bundle = Athena::services($router, toolBundles: [$tools]);

        self::assertInstanceOf(AthenaBundle::class, $bundle);
    }

    #[Test]
    public function configHoldsRouterAndHooks(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $config = new AthenaConfig($router);

        self::assertSame($router, $config->router);
        self::assertSame([], $config->hooks);
        self::assertSame([], $config->mcpServers);
    }
}

final class NullProvider implements Provider
{
    public function perform(Invocation $invocation, Runtime $runtime): Stream
    {
        return Stream::from([]);
    }

    public function capabilities(): Capabilities
    {
        return Capabilities::of();
    }
}
