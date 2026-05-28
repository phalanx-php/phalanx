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
use Phalanx\Harness\Agent\ApprovalAuthorizer;
use Phalanx\Harness\Agent\AthenaServiceBundle;
use Phalanx\Harness\Agent\LlmRequestRecordingRouter;
use Phalanx\Harness\Agent\OllamaConfig;
use Phalanx\Harness\Agent\TemplateAgent;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Effect\Authorizer;
use Phalanx\Service\ServiceBundle;
use Phalanx\Service\ServiceCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AthenaServiceBundleTest extends TestCase
{
    #[Test]
    public function fromCreatesBundle(): void
    {
        $athenaBundle = $this->makeAthenaBundle();

        $bundle = AthenaServiceBundle::from($athenaBundle);

        self::assertInstanceOf(AthenaServiceBundle::class, $bundle);
        self::assertInstanceOf(ServiceBundle::class, $bundle);
    }

    #[Test]
    public function servicesComposeAthenaBundleWithHarnessOverrides(): void
    {
        $athenaBundle = $this->makeAthenaBundle();
        $bundle = AthenaServiceBundle::from($athenaBundle);
        $catalog = new ServiceCatalog();

        $bundle->services($catalog, new AppContext([]));
        $graph = $catalog->compile();
        $factory = $graph->resolve(AthenaConfig::class)->factoryFn;
        self::assertNotNull($factory);

        $config = $factory();

        self::assertInstanceOf(AthenaConfig::class, $config);
        self::assertInstanceOf(LlmRequestRecordingRouter::class, $config->router);
        self::assertSame(ApprovalAuthorizer::class, $graph->alias(Authorizer::class));
        self::assertSame(TemplateAgent::class, $graph->alias(Agent::class));
        self::assertSame(AgentExecutor::class, $graph->alias(AgentExecutorContract::class));
    }

    #[Test]
    public function servicesInstallRequestRecordingRouter(): void
    {
        $catalog = new ServiceCatalog();
        $bundle = AthenaServiceBundle::from($this->makeAthenaBundle());

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
            'HARNESS_OLLAMA_BASE_URL' => 'http://example.test:11434',
            'HARNESS_OLLAMA_MODEL' => 'llama3.1',
            'HARNESS_MAX_INVOCATIONS' => '2',
        ]);
        $catalog = new ServiceCatalog();

        AthenaServiceBundle::ollama()->services($catalog, $context);

        $graph = $catalog->compile();
        $factory = $graph->resolve(OllamaConfig::class)->factoryFn;
        self::assertNotNull($factory);
        $config = $factory();

        self::assertInstanceOf(OllamaConfig::class, $config);
        self::assertSame('http://example.test:11434', $config->baseUrl);
        self::assertSame('llama3.1', $config->model);
        self::assertSame(2, $config->maxInvocations);
        self::assertSame(TemplateAgent::class, $graph->alias(Agent::class));
        self::assertSame(AgentExecutor::class, $graph->alias(AgentExecutorContract::class));
        self::assertSame(Config::class, $graph->resolve(Config::class)->type);
    }

    #[Test]
    public function ollamaConfigIgnoresOldTheatronContextKeys(): void
    {
        $context = new AppContext([
            'THEATRON_OLLAMA_BASE_URL' => 'http://old.example.test:11434',
            'THEATRON_OLLAMA_MODEL' => 'old-model',
            'THEATRON_MAX_INVOCATIONS' => '99',
        ]);

        $config = OllamaConfig::fromContext($context);

        self::assertSame('http://localhost:11434', $config->baseUrl);
        self::assertSame('qwen3:4b', $config->model);
        self::assertSame(3, $config->maxInvocations);
    }

    #[Test]
    public function ollamaHarnessExposesContextSchema(): void
    {
        $schema = AthenaServiceBundle::contextSchema();
        $keys = array_map(static fn($key): string => $key->name, $schema->all());

        self::assertSame([
            'HARNESS_OLLAMA_BASE_URL',
            'HARNESS_OLLAMA_MODEL',
            'HARNESS_MAX_INVOCATIONS',
        ], $keys);
        self::assertSame(AthenaServiceBundle::class, $schema->all()[0]->owner);
        self::assertStringContainsString('HARNESS_OLLAMA_MODEL', $schema->render());
    }

    #[Test]
    public function ollamaWithCustomAgentClassResolvesToThatAgent(): void
    {
        $context = new AppContext([]);
        $catalog = new ServiceCatalog();

        AthenaServiceBundle::ollama(TemplateAgent::class)->services($catalog, $context);
        $graph = $catalog->compile();

        self::assertSame(TemplateAgent::class, $graph->alias(Agent::class));
    }

    private function makeAthenaBundle(): AthenaBundle
    {
        return new AthenaBundle($this->createStub(InvocationRouter::class));
    }
}
