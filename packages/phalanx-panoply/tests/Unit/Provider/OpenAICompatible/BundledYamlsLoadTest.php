<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\OpenAICompatible;

use Phalanx\Panoply\Artifact\Kind as ArtifactKind;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Effect\Kind as EffectKind;
use Phalanx\Panoply\Effects;
use Phalanx\Panoply\Invocation;
use Phalanx\Panoply\Output;
use Phalanx\Panoply\Provider\Loader;
use Phalanx\Panoply\Provider\Needs as ProviderNeeds;
use Phalanx\Panoply\Provider\OpenAI\ChatProvider;
use Phalanx\Panoply\Provider\OpenAI\ChatRequestBuilder;
use Phalanx\Panoply\Provider\Preference;
use Phalanx\Panoply\Transport\Fake\Transport as FakeTransport;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
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
        $config = Loader::fromFile(self::yamlPath('together.panoply.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('https://api.together.xyz/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function groqYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('groq.panoply.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('https://api.groq.com/openai/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function openrouterYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('openrouter.panoply.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('https://openrouter.ai/api/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
        self::assertSame('https://phalanx.test', $config->defaultHeaders['HTTP-Referer']);
        self::assertSame('Phalanx-Panoply', $config->defaultHeaders['X-Title']);
    }

    #[Test]
    public function lmstudioYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('lmstudio.panoply.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('http://localhost:1234/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function llamacppYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('llamacpp.panoply.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
        self::assertSame('http://localhost:8080/v1', $config->baseUrl);
        self::assertNotEmpty($config->models);
    }

    #[Test]
    public function togetherConfigHasAtLeastThreeModels(): void
    {
        $config = Loader::fromFile(self::yamlPath('together.panoply.yaml'));

        self::assertGreaterThanOrEqual(3, count($config->models));
    }

    #[Test]
    public function groqConfigHasAtLeastThreeModels(): void
    {
        $config = Loader::fromFile(self::yamlPath('groq.panoply.yaml'));

        self::assertGreaterThanOrEqual(3, count($config->models));
    }

    #[Test]
    public function openrouterConfigHasAtLeastOneModel(): void
    {
        $config = Loader::fromFile(self::yamlPath('openrouter.panoply.yaml'));

        self::assertGreaterThanOrEqual(1, count($config->models));
    }

    #[Test]
    public function lmstudioConfigHasLocalCatchAllModel(): void
    {
        $config = Loader::fromFile(self::yamlPath('lmstudio.panoply.yaml'));
        $model  = $config->models[0];

        self::assertSame('local', $model->name);
        self::assertContains('local-latest', $model->aliases);
    }

    #[Test]
    public function llamacppConfigHasLocalCatchAllModel(): void
    {
        $config = Loader::fromFile(self::yamlPath('llamacpp.panoply.yaml'));
        $model  = $config->models[0];

        self::assertSame('local', $model->name);
        self::assertContains('local-latest', $model->aliases);
    }

    #[Test]
    public function chatProviderInstantiatesWithTogetherBaseUrl(): void
    {
        $config = Loader::fromFile(self::yamlPath('together.panoply.yaml'));

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
        $config = Loader::fromFile(self::yamlPath('groq.panoply.yaml'));

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
        $config = Loader::fromFile(self::yamlPath('openrouter.panoply.yaml'));

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
        return dirname(__DIR__, 4) . '/src/Provider/OpenAICompatible/' . $file;
    }
}
