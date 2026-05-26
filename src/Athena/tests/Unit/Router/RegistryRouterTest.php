<?php

declare(strict_types=1);

namespace Phalanx\Athena\Tests\Unit\Router;

use Phalanx\Athena\Router\RegistryRouter;
use Phalanx\Iris\HttpClient;
use Phalanx\Panoply\Agent;
use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Context;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Loader;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\Ollama\ChatProvider;
use Phalanx\Panoply\Provider\Registry;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistryRouterTest extends TestCase
{
    #[Test]
    public function routesOllamaModelAlias(): void
    {
        $config = Loader::fromFile(self::ollamaYamlPath());
        $registry = Registry::empty()->with($config);
        $router = new RegistryRouter($registry, 'llama3.1');

        $scope = $this->scopeWithHttpClient();
        $provider = $router->route($scope, self::agent(), self::invocation());

        self::assertInstanceOf(ChatProvider::class, $provider);
    }

    #[Test]
    public function throwsOnUnknownModelAlias(): void
    {
        $router = new RegistryRouter(Registry::empty(), 'nonexistent-model');
        $scope = $this->createStub(TaskScope::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("No provider registered for model alias 'nonexistent-model'");

        $router->route($scope, self::agent(), self::invocation());
    }

    #[Test]
    public function passesCredentialsToFactoryForAuthFreeProvider(): void
    {
        $config = Loader::fromFile(self::ollamaYamlPath());
        $registry = Registry::empty()->with($config);
        $router = new RegistryRouter($registry, 'llama3.1', credentials: ['ollama' => 'unused-key']);

        $scope = $this->scopeWithHttpClient();
        $provider = $router->route($scope, self::agent(), self::invocation());

        self::assertInstanceOf(ChatProvider::class, $provider);
    }

    private function scopeWithHttpClient(): TaskScope
    {
        $httpClient = $this->createStub(HttpClient::class);
        $scope = $this->createStub(TaskScope::class);
        $scope->method('service')->willReturn($httpClient);

        return $scope;
    }

    private static function ollamaYamlPath(): string
    {
        return dirname(__DIR__, 4) . '/Panoply/src/Provider/Ollama/ollama.panoply.yaml';
    }

    private static function agent(): Agent
    {
        return new class implements Agent {
            public string $id { get => 'odysseus'; }
            public string $name { get => 'Odysseus'; }
            public string $purpose { get => 'Navigate the straits.'; }
            public Output $output { get => Output::text(); }
            public Context $context { get => Context::new(); }
            public Effects $effects { get => Effects::none(); }
            public ProviderNeeds $provider { get => ProviderNeeds::new(); }
            public Capabilities $capabilities { get => Capabilities::of(Capability::Reasoning); }
            public TransportNeeds $transport { get => TransportNeeds::new(); }
        };
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv-test',
            agentId: 'odysseus',
            activityId: 'act-test',
            contextHash: 'hash-test',
            instructions: 'Test invocation.',
            output: Output::text(),
            effects: Effects::none(),
            provider: ProviderNeeds::new(),
            transport: TransportNeeds::new(),
        );
    }
}
