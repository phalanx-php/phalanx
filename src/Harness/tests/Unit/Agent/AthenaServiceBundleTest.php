<?php

declare(strict_types=1);

namespace Phalanx\Harness\Tests\Unit\Agent;

use Phalanx\Athena\Activity\Config;
use Phalanx\Athena\AthenaBundle;
use Phalanx\Athena\AthenaConfig;
use Phalanx\Athena\Router\InvocationRouter;
use Phalanx\Boot\AppContext;
use Phalanx\Harness\Agent\AgentExecutor;
use Phalanx\Harness\Agent\AgentExecutorContract;
use Phalanx\Harness\Agent\AthenaServiceBundle;
use Phalanx\Harness\Agent\LlmRequestRecordingRouter;
use Phalanx\Harness\Agent\OllamaConfig;
use Phalanx\Harness\Agent\TemplateAgent;
use Phalanx\Panoply\Agent;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceCatalog;
use Phalanx\Service\ServiceConfig;
use Phalanx\Service\Services;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AthenaServiceBundleTest extends TestCase
{
    #[Test]
    public function fromCreatesBundle(): void
    {
        $athenaBundle = self::makeAthenaBundle();

        $bundle = AthenaServiceBundle::from($athenaBundle);

        self::assertInstanceOf(AthenaServiceBundle::class, $bundle);
        self::assertInstanceOf(ServiceBundle::class, $bundle);
    }

    #[Test]
    public function fromHoldsDelegate(): void
    {
        $athenaBundle = self::makeAthenaBundle();

        $bundle = AthenaServiceBundle::from($athenaBundle);

        self::assertSame($athenaBundle, $bundle->athenaBundle);
    }

    #[Test]
    public function delegatesToAthenaBundleOnServices(): void
    {
        $athenaBundle = self::makeAthenaBundle();
        $bundle = AthenaServiceBundle::from($athenaBundle);

        $serviceConfig = $this->createStub(ServiceConfig::class);
        $serviceConfig->method('factory')->willReturnSelf();
        $serviceConfig->method('needs')->willReturnSelf();

        $services = $this->createMock(Services::class);
        $services->method('singleton')->willReturn($serviceConfig);
        $services->method('scoped')->willReturn($serviceConfig);
        $services->method('has')->willReturn(false);

        $services->expects(self::atLeastOnce())->method('singleton');

        $context = new AppContext([]);

        $bundle->services($services, $context);
    }

    #[Test]
    public function servicesInstallRequestRecordingRouter(): void
    {
        $catalog = new ServiceCatalog(new AppContext());
        $bundle = AthenaServiceBundle::from(self::makeAthenaBundle());

        $bundle->services($catalog, new AppContext());

        $factory = $catalog->compile()->resolve(AthenaConfig::class)->factoryFn;
        self::assertNotNull($factory);

        $config = $factory();

        self::assertInstanceOf(AthenaConfig::class, $config);
        self::assertInstanceOf(LlmRequestRecordingRouter::class, $config->router);
    }

    #[Test]
    public function ollamaServicesInstallDefaultAgentExecutorAndConfig(): void
    {
        $context = new AppContext([
            'THEATRON_OLLAMA_BASE_URL' => 'http://example.test:11434',
            'THEATRON_OLLAMA_MODEL' => 'llama3.1',
            'THEATRON_MAX_INVOCATIONS' => '2',
        ]);
        $catalog = new ServiceCatalog($context);

        AthenaServiceBundle::ollama()->services($catalog, $context);

        $graph = $catalog->compile();
        $config = $graph->contextConfig(OllamaConfig::class);

        self::assertInstanceOf(OllamaConfig::class, $config);
        self::assertSame('http://example.test:11434', $config->baseUrl);
        self::assertSame('llama3.1', $config->model);
        self::assertSame(2, $config->maxInvocations);
        self::assertSame(TemplateAgent::class, $graph->alias(Agent::class));
        self::assertSame(AgentExecutor::class, $graph->alias(AgentExecutorContract::class));
        self::assertSame(Config::class, $graph->resolve(Config::class)->type);
    }

    private static function makeAthenaBundle(): AthenaBundle
    {
        $router = new class implements InvocationRouter {
            public function route(
                \Phalanx\Scope\TaskScope $scope,
                \Phalanx\Panoply\Agent $agent,
                \Phalanx\Panoply\Invocation $invocation,
            ): \Phalanx\Panoply\Provider {
                throw new \LogicException('Not implemented in test stub.');
            }
        };

        return new AthenaBundle($router);
    }
}
