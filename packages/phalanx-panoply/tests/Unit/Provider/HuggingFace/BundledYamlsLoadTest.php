<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider\HuggingFace;

use Phalanx\Panoply\Provider\HuggingFace\InferenceProvider;
use Phalanx\Panoply\Provider\Loader;
use Phalanx\Panoply\Provider\OpenAI\ChatProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies both bundled HuggingFace YAML configs load cleanly and point at
 * the correct wire translators.
 *
 * huggingface.panoply.yaml  → InferenceProvider (custom mapper composition)
 * huggingface-dedicated.panoply.yaml → OpenAI\ChatProvider (zero-new-code reuse)
 */
final class BundledYamlsLoadTest extends TestCase
{
    #[Test]
    public function inferenceYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('huggingface.panoply.yaml'));

        self::assertSame(InferenceProvider::class, $config->wireTranslator);
        self::assertSame('https://api-inference.huggingface.co', $config->baseUrl);
    }

    #[Test]
    public function inferenceYamlHasThreeModels(): void
    {
        $config = Loader::fromFile(self::yamlPath('huggingface.panoply.yaml'));

        self::assertCount(3, $config->models);
    }

    #[Test]
    public function inferenceYamlModelsHaveExpectedIds(): void
    {
        $config    = Loader::fromFile(self::yamlPath('huggingface.panoply.yaml'));
        $modelIds  = array_map(static fn ($m) => $m->modelId, $config->models);

        self::assertContains('meta-llama/Meta-Llama-3.1-70B-Instruct', $modelIds);
        self::assertContains('mistralai/Mistral-7B-Instruct-v0.3', $modelIds);
        self::assertContains('Qwen/Qwen2.5-72B-Instruct', $modelIds);
    }

    #[Test]
    public function dedicatedYamlLoads(): void
    {
        $config = Loader::fromFile(self::yamlPath('huggingface-dedicated.panoply.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
    }

    #[Test]
    public function dedicatedYamlHasCatchAllModel(): void
    {
        $config = Loader::fromFile(self::yamlPath('huggingface-dedicated.panoply.yaml'));
        $model  = $config->models[0];

        self::assertSame('local-dedicated', $model->name);
        self::assertSame('tgi', $model->modelId);
        self::assertContains('dedicated', $model->aliases);
        self::assertContains('endpoint', $model->aliases);
    }

    #[Test]
    public function dedicatedYamlWireTranslatorIsOpenAIChatProvider(): void
    {
        // This validates the "zero new PHP" reuse pattern: the dedicated-endpoint
        // YAML points at OpenAI\ChatProvider so any TGI-compatible endpoint works
        // with no new provider code.
        $config = Loader::fromFile(self::yamlPath('huggingface-dedicated.panoply.yaml'));

        self::assertSame(ChatProvider::class, $config->wireTranslator);
    }

    private static function yamlPath(string $file): string
    {
        return dirname(__DIR__, 4) . '/src/Provider/HuggingFace/' . $file;
    }
}
