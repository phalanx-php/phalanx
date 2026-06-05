<?php

declare(strict_types=1);

namespace Phalanx\Agent\Tests\Unit\Router;

use RuntimeException;

use Phalanx\Agent\Router\RegistryRouter;
use Phalanx\HttpClient\Client;
use Phalanx\AiProviders\Agent;
use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Context;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Loader;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\Ollama\ChatProvider;
use Phalanx\AiProviders\Provider\Registry;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use Phalanx\Scope\TaskScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegistryRouterTest extends TestCase
{
    #[Test]
    public function routesOllamaModelAlias(): void
    {
        $config = Loader::fromFile(ChatProvider::configPath());
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
        $config = Loader::fromFile(ChatProvider::configPath());
        $registry = Registry::empty()->with($config);
        $router = new RegistryRouter($registry, 'llama3.1', credentials: ['ollama' => 'unused-key']);

        $scope = $this->scopeWithHttpClient();
        $provider = $router->route($scope, self::agent(), self::invocation());

        self::assertInstanceOf(ChatProvider::class, $provider);
    }

    private function scopeWithHttpClient(): TaskScope
    {
        $httpClient = $this->createStub(\Phalanx\HttpClient\Client::class);
        $scope = $this->createStub(TaskScope::class);
        $scope->method('service')->willReturn($httpClient);

        return $scope;
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
