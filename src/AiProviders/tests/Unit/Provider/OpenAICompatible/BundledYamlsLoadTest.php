<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\OpenAICompatible;

use Phalanx\AiProviders\Artifact\Kind as ArtifactKind;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Effect\Kind as EffectKind;
use Phalanx\AiProviders\Effects;
use Phalanx\AiProviders\Invocation;
use Phalanx\AiProviders\Output;
use Phalanx\AiProviders\Provider\Loader;
use Phalanx\AiProviders\Provider\Needs as ProviderNeeds;
use Phalanx\AiProviders\Provider\OpenAI\ChatProvider;
use Phalanx\AiProviders\Provider\OpenAI\ChatRequestBuilder;
use Phalanx\AiProviders\Provider\Preference;
use Phalanx\AiProviders\Transport\Fake\Transport as FakeTransport;
use Phalanx\AiProviders\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies each bundled OpenAICompatible YAML loads cleanly and points at
 * the correct wire translator, base URL, and model catalog.
 */
final class BundledYamlsLoadTest extends TestCase
{
    #[Test]
    public function togetherYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('together.ai-providers.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('https://api.together.xyz/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function groqYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('groq.ai-providers.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('https://api.groq.com/openai/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function openrouterYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('openrouter.ai-providers.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('https://openrouter.ai/api/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
        self::assertSame('https://phalanx.test', $config->defaultHeaders['HTTP-Referer']);
        self::assertSame('Phalanx-AiProviders', $config->defaultHeaders['X-Title']);
    }

    #[Test]
    public function lmstudioYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('lmstudio.ai-providers.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('http://localhost:1234/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function llamacppYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('llamacpp.ai-providers.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('http://localhost:8080/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function togetherConfigHasAtLeastThreeModels(): void
    {
        $config = Loader::fromFile(self::yamlPath('together.ai-providers.yaml'));

        self::assertGreaterThanOrEqual(3, count($config->models));
    }

    #[Test]
    public function groqConfigHasAtLeastThreeModels(): void
    {
        $config = Loader::fromFile(self::yamlPath('groq.ai-providers.yaml'));

        self::assertGreaterThanOrEqual(3, count($config->models));
    }

    #[Test]
    public function openrouterConfigHasAtLeastOneModel(): void
    {
        $config = Loader::fromFile(self::yamlPath('openrouter.ai-providers.yaml'));

        self::assertGreaterThanOrEqual(1, count($config->models));
    }

    #[Test]
    public function lmstudioConfigHasLocalCatchAllModel(): void
    {
        $config = Loader::fromFile(self::yamlPath('lmstudio.ai-providers.yaml'));
        $model = $config->models[0];

        self::assertSame('local', $model->name);
        self::assertContains('local-latest', $model->aliases);
    }

    #[Test]
    public function llamacppConfigHasLocalCatchAllModel(): void
    {
        $config = Loader::fromFile(self::yamlPath('llamacpp.ai-providers.yaml'));
        $model = $config->models[0];

        self::assertSame('local', $model->name);
        self::assertContains('local-latest', $model->aliases);
    }

    #[Test]
    public function chatProviderInstantiatesWithTogetherBaseUrl(): void
    {
        $config = Loader::fromFile(self::yamlPath('together.ai-providers.yaml'));

        $provider = new ChatProvider(
            transport: new FakeTransport([]),
            apiKey: 'key_together',
            model: $config->models[0],
            baseUrl: (string) $config->baseUrl,
            defaultHeaders: $config->defaultHeaders,
        );

        // Provider constructed without exception; base URL flows through.
        self::assertSame('https://api.together.xyz/v1', $provider->baseUrl);
    }

    #[Test]
    public function groqYamlProducesCorrectRequestUrl(): void
    {
        // Proves end-to-end: YAML base_url (which already contains /v1) →
        // ChatProvider → ChatRequestBuilder → correct request URL.
        // Groq's base_url is "https://api.groq.com/openai/v1" (ends with /v1),
        // so the builder appends only /chat/completions — no double /v1.
        $config = Loader::fromFile(self::yamlPath('groq.ai-providers.yaml'));

        $request = ChatRequestBuilder::build(
            self::invocation(),
            $config->models[0],
            'key_groq',
            (string) $config->baseUrl,
        );

        self::assertSame('https://api.groq.com/openai/v1/chat/completions', $request->url);
    }

    #[Test]
    public function openrouterDefaultHeadersFlowIntoProvider(): void
    {
        $config = Loader::fromFile(self::yamlPath('openrouter.ai-providers.yaml'));

        $provider = new ChatProvider(
            transport: new FakeTransport([]),
            apiKey: 'key_openrouter',
            model: $config->models[0],
            baseUrl: (string) $config->baseUrl,
            defaultHeaders: $config->defaultHeaders,
        );

        self::assertSame('https://phalanx.test', $provider->defaultHeaders['HTTP-Referer']);
    }

    private static function invocation(): Invocation
    {
        return Invocation::of(
            id: 'inv_leonidas',
            agentId: 'leonidas',
            activityId: 'act_thermopylae',
            contextHash: str_repeat('g', 64),
            instructions: 'Hold the pass.',
            output: Output::artifact(ArtifactKind::Thesis),
            effects: Effects::allow(EffectKind::WebFetch),
            provider: ProviderNeeds::new()->prefer(Preference::LocalFirst)->require(Capability::Reasoning),
            transport: TransportNeeds::new()->streaming(),
            dynamicContext: ['user_input' => 'What is the plan?'],
        );
    }

    private static function yamlPath(string $file): string
    {
        return dirname(__DIR__, 4) . '/Provider/OpenAICompatible/' . $file;
    }
}
