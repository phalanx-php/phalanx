<?php

declare(strict_types=1);

namespace Phalanx\Agents\Tests\Unit;

use Phalanx\Agents\Agents;
use Phalanx\Agents\AgentsBundle;
use Phalanx\Agents\AgentsConfig;
use Phalanx\Agents\Router\SingleProviderRouter;
use Phalanx\Agents\Tool\ToolBundle;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Stream;
use Phalanx\Service\ServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentsBundleTest extends TestCase
{
    #[Test]
    public function bundleExtendsServiceBundle(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $bundle = new AgentsBundle($router);

        self::assertInstanceOf(ServiceBundle::class, $bundle);
    }

    #[Test]
    public function moduleEntryServicesReturnsBundle(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $bundle = Agents::services($router);

        self::assertInstanceOf(AgentsBundle::class, $bundle);
    }

    #[Test]
    public function moduleEntryServicesAcceptsToolBundles(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $tools = new ToolBundle();
        $bundle = Agents::services($router, toolBundles: [$tools]);

        self::assertInstanceOf(AgentsBundle::class, $bundle);
    }

    #[Test]
    public function configHoldsRouterAndHooks(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $config = new AgentsConfig($router);

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
