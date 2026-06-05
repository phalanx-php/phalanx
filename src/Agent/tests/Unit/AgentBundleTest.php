<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit;

use Phalanx\Agent\Agent;
use Phalanx\Agent\AgentBundle;
use Phalanx\Agent\AgentConfig;
use Phalanx\Agent\Router\SingleProviderRouter;
use Phalanx\Agent\Tool\ToolBundle;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Provider;
use Phalanx\AiProviders\Runtime;
use Phalanx\AiProviders\Stream;
use Phalanx\Service\ServiceBundle;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentBundleTest extends TestCase
{
    #[Test]
    public function bundleExtendsServiceBundle(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $bundle = new AgentBundle($router);

        self::assertInstanceOf(ServiceBundle::class, $bundle);
    }

    #[Test]
    public function facadeServicesReturnsBundle(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $bundle = Agent::services($router);

        self::assertInstanceOf(AgentBundle::class, $bundle);
    }

    #[Test]
    public function facadeServicesAcceptsToolBundles(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $tools = new ToolBundle();
        $bundle = Agent::services($router, toolBundles: [$tools]);

        self::assertInstanceOf(AgentBundle::class, $bundle);
    }

    #[Test]
    public function configHoldsRouterAndHooks(): void
    {
        $router = new SingleProviderRouter(new NullProvider());
        $config = new AgentConfig($router);

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
