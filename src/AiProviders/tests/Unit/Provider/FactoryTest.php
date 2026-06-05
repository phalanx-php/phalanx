<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Provider\Config;
use Phalanx\AiProviders\Provider\Config\Model;
use Phalanx\AiProviders\Provider\Factory;
use Phalanx\AiProviders\Provider\FactoryError;
use Phalanx\AiProviders\Provider\Loader;
use Phalanx\AiProviders\Provider\Ollama\ChatProvider as OllamaChatProvider;
use Phalanx\AiProviders\Provider\OpenAI\ChatProvider as OpenAIChatProvider;
use Phalanx\AiProviders\Provider\Resolution;
use Phalanx\AiProviders\Transport;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FactoryTest extends TestCase
{
    #[Test]
    public function createsOllamaProviderWithoutApiKey(): void
    {
        $config = Loader::fromFile(OllamaChatProvider::configPath());
        $model = $config->models[0];
        $transport = $this->createStub(Transport::class);

        $provider = Factory::create(new Resolution($config, $model), $transport);

        self::assertInstanceOf(OllamaChatProvider::class, $provider);
    }

    #[Test]
    public function createsOpenAIProviderWithApiKey(): void
    {
        $config = self::openAiConfig();
        $model = $config->models[0];
        $transport = $this->createStub(Transport::class);

        $provider = Factory::create(new Resolution($config, $model), $transport, apiKey: 'sk-test-key');

        self::assertInstanceOf(OpenAIChatProvider::class, $provider);
    }

    #[Test]
    public function throwsOnMissingWireTranslator(): void
    {
        $config = Config::of(
            id: 'broken',
            displayName: 'Broken',
            models: [self::model()],
            capabilities: Capabilities::of(Capability::Reasoning),
            transport: TransportNeeds::new(),
            wireTranslator: null,
        );
        $transport = $this->createStub(Transport::class);

        $this->expectException(FactoryError::class);
        $this->expectExceptionMessage('no wire_translator');

        Factory::create(new Resolution($config, $config->models[0]), $transport);
    }

    #[Test]
    public function throwsOnMissingApiKeyWhenRequired(): void
    {
        $config = self::openAiConfig();
        $transport = $this->createStub(Transport::class);

        $this->expectException(FactoryError::class);
        $this->expectExceptionMessage('requires an API key');

        Factory::create(new Resolution($config, $config->models[0]), $transport);
    }

    #[Test]
    public function usesBaseUrlFromConfig(): void
    {
        $config = Loader::fromFile(OllamaChatProvider::configPath());
        $transport = $this->createStub(Transport::class);

        $provider = Factory::create(new Resolution($config, $config->models[0]), $transport);

        self::assertInstanceOf(OllamaChatProvider::class, $provider);
        self::assertSame('http://localhost:11434', $provider->baseUrl);
    }

    #[Test]
    public function usesDefaultHeadersFromConfig(): void
    {
        $config = Config::of(
            id: 'openai',
            displayName: 'OpenAI',
            models: [self::model()],
            capabilities: Capabilities::of(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming()->cancellable(),
            wireTranslator: OpenAIChatProvider::class,
            defaultHeaders: ['X-Custom' => 'test-value'],
        );
        $transport = $this->createStub(Transport::class);

        $provider = Factory::create(
            new Resolution($config, $config->models[0]),
            $transport,
            apiKey: 'sk-test',
        );

        self::assertInstanceOf(OpenAIChatProvider::class, $provider);
        self::assertSame(['X-Custom' => 'test-value'], $provider->defaultHeaders);
    }

    private static function openAiConfig(): Config
    {
        return Config::of(
            id: 'openai',
            displayName: 'OpenAI',
            models: [self::model()],
            capabilities: Capabilities::of(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming()->cancellable(),
            wireTranslator: OpenAIChatProvider::class,
        );
    }

    private static function model(): Model
    {
        return Model::of(
            name: 'gpt-4o',
            modelId: 'gpt-4o',
            aliases: [],
            capabilities: Capabilities::of(Capability::Reasoning),
        );
    }
}
