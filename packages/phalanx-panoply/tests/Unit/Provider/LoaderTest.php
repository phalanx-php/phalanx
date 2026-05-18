<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider;

use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Provider\Loader;
use Phalanx\Panoply\Provider\ValidationError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins spec acceptance gate #13.
 */
final class LoaderTest extends TestCase
{
    #[Test]
    public function validYamlLoads(): void
    {
        $config = Loader::fromFile(self::fixtureFile());

        self::assertSame('anthropic', $config->id);
        self::assertSame('Olympus Sky-Provider', $config->displayName);
    }

    #[Test]
    public function validYamlLoadsModels(): void
    {
        $config = Loader::fromFile(self::fixtureFile());

        self::assertCount(1, $config->models);
        self::assertSame('claude-opus-4-7', $config->models[0]->name);
    }

    #[Test]
    public function missingWireTranslatorClassResolvesToNull(): void
    {
        // Point at a class that will never exist; Loader must resolve to null.
        $yaml = self::validYaml();
        $nonexistent = 'Phalanx\\\\Panoply\\\\__NONEXISTENT_FOR_TEST__\\\\Provider';
        $yaml = str_replace('wire_translator: null', "wire_translator: \"{$nonexistent}\"", $yaml);

        $config = Loader::fromString($yaml, 'test.yaml');

        self::assertNull($config->wireTranslator);
    }

    #[Test]
    public function realWireTranslatorClassResolves(): void
    {
        // The fixture points at the real Anthropic Provider class which is
        // loaded via Composer autoload. Loader must resolve it to the
        // class-string, not null.
        $config = Loader::fromFile(self::fixtureFile());

        self::assertSame(\Phalanx\Panoply\Provider\Anthropic\Provider::class, $config->wireTranslator);
    }

    #[Test]
    public function validYamlLoadsCapabilities(): void
    {
        $config = Loader::fromFile(self::fixtureFile());

        self::assertTrue($config->capabilities->has(Capability::Reasoning));
        self::assertTrue($config->capabilities->has(Capability::ToolUse));
    }

    #[Test]
    public function validYamlLoadsTransport(): void
    {
        $config = Loader::fromFile(self::fixtureFile());

        $transport = $config->transport->toCanonical();
        self::assertTrue($transport['streaming']);
        self::assertTrue($transport['cancellable']);
        self::assertTrue($transport['backpressure']);
        self::assertFalse($transport['partial_json']);
    }

    #[Test]
    public function modelAliasesAreLoaded(): void
    {
        $config = Loader::fromFile(self::fixtureFile());
        $model = $config->models[0];

        self::assertContains('opus', $model->aliases);
        self::assertContains('opus-4', $model->aliases);
        self::assertContains('opus-latest', $model->aliases);
    }

    #[Test]
    public function modelPricingIsLoaded(): void
    {
        $config = Loader::fromFile(self::fixtureFile());

        self::assertSame(0.003, $config->models[0]->inputPricing);
        self::assertSame(0.015, $config->models[0]->outputPricing);
    }

    #[Test]
    public function unknownTopLevelKeyThrowsValidationError(): void
    {
        $yaml = self::validYaml() . "\nunknown_key: sparta\n";

        $this->expectException(ValidationError::class);

        Loader::fromString($yaml, 'test.yaml');
    }

    #[Test]
    public function missingRequiredKeyThrowsValidationError(): void
    {
        // Strip the id line.
        $yaml = preg_replace('/^id:.*\n/m', '', self::validYaml());

        $this->expectException(ValidationError::class);

        Loader::fromString((string) $yaml, 'test.yaml');
    }

    #[Test]
    public function allViolationsAccumulated(): void
    {
        $yaml = "wire_translator: null\n";
        // Missing: id, display_name, models, capabilities, transport — 5 violations minimum.

        try {
            Loader::fromString($yaml, 'test.yaml');
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            $message = implode("\n", $e->violations);
            self::assertStringContainsString('id', $message, 'missing id reported');
            self::assertStringContainsString('display_name', $message, 'missing display_name reported');
            self::assertStringContainsString('models', $message, 'missing models reported');
            self::assertStringContainsString('capabilities', $message, 'missing capabilities reported');
            self::assertStringContainsString('transport', $message, 'missing transport reported');
            self::assertGreaterThanOrEqual(5, count($e->violations));
        }
    }

    #[Test]
    public function missingModelsKeyThrowsWithMissingViolation(): void
    {
        $yaml = <<<'YAML'
id: sparta
display_name: Sparta
capabilities:
  closed: []
  custom: []
transport:
  streaming: true
  cancellable: true
wire_translator: null
YAML;

        try {
            Loader::fromString($yaml, 'test.yaml');
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            self::assertContains('Missing required key: models', $e->violations);
        }
    }

    #[Test]
    public function invalidTransportTypeThrows(): void
    {
        $yaml = self::validYaml();
        $yaml = str_replace('streaming: true', 'streaming: "yes"', $yaml);

        $this->expectException(ValidationError::class);

        Loader::fromString($yaml, 'test.yaml');
    }

    #[Test]
    public function fromStringWithDefaultLabel(): void
    {
        try {
            Loader::fromString('wire_translator: null');
        } catch (ValidationError $e) {
            self::assertStringContainsString('<inline>', $e->getMessage());

            return;
        }

        self::fail('Expected ValidationError');
    }

    #[Test]
    public function nonExistentFileThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Loader::fromFile('/nonexistent/path/provider.yaml');
    }

    #[Test]
    public function unknownKeysInNestedObjectsAreRejected(): void
    {
        $yaml = <<<YAML
id: zeus
display_name: "Olympus Provider"
models:
  - name: zeus-thunderbolt
    model_id: zt-1
    aliases: ["zeus"]
    capabilities:
      closed: ["reasoning"]
      custom: []
    deprecated: true
capabilities:
  closed: ["reasoning"]
  custom: []
  beta: true
transport:
  streaming: true
  cancellable: true
  retry: true
wire_translator: null
YAML;

        try {
            Loader::fromString($yaml);
            self::fail('Expected ValidationError');
        } catch (ValidationError $e) {
            $message = implode("\n", $e->violations);
            self::assertStringContainsString("models[0]", $message);
            self::assertStringContainsString("deprecated", $message);
            self::assertStringContainsString("capabilities", $message);
            self::assertStringContainsString("beta", $message);
            self::assertStringContainsString("transport", $message);
            self::assertStringContainsString("retry", $message);
        }
    }

    #[Test]
    public function loaderReadsBaseUrlFromYaml(): void
    {
        $yaml = self::validYaml() . "\nbase_url: \"https://api.together.xyz/v1\"\n";

        $config = Loader::fromString($yaml, 'test.yaml');

        self::assertSame('https://api.together.xyz/v1', $config->baseUrl);
    }

    #[Test]
    public function loaderReadsDefaultHeadersFromYaml(): void
    {
        $yaml = self::validYaml();
        $yaml .= "\ndefault_headers:\n  HTTP-Referer: \"https://phalanx.test\"\n  X-Title: \"Sparta\"\n";

        $config = Loader::fromString($yaml, 'test.yaml');

        self::assertSame(['HTTP-Referer' => 'https://phalanx.test', 'X-Title' => 'Sparta'], $config->defaultHeaders);
    }

    #[Test]
    public function loaderRejectsNonStringHeaderValues(): void
    {
        $yaml = self::validYaml() . "\ndefault_headers:\n  X-Count: 42\n";

        $this->expectException(ValidationError::class);

        Loader::fromString($yaml, 'test.yaml');
    }

    #[Test]
    public function loaderBaseUrlAndDefaultHeadersAreOptional(): void
    {
        $config = Loader::fromString(self::validYaml(), 'test.yaml');

        self::assertNull($config->baseUrl);
        self::assertSame([], $config->defaultHeaders);
    }

    #[Test]
    public function fakeYamlRoundTrip(): void
    {
        $path = dirname(__DIR__, 3) . '/src/Provider/Fake/fake.panoply.yaml';
        $config = Loader::fromFile($path);

        self::assertSame('fake', $config->id);
        self::assertSame(\Phalanx\Panoply\Provider\Fake\Provider::class, $config->wireTranslator);

        self::assertCount(1, $config->models);
        $model = $config->models[0];
        self::assertSame('oracle-of-delphi', $model->name);
        self::assertContains('delphi', $model->aliases);
        self::assertContains('oracle', $model->aliases);
    }

    private static function fixtureFile(): string
    {
        return __DIR__ . '/../../Fixtures/Provider/anthropic.panoply.yaml';
    }

    private static function validYaml(): string
    {
        return <<<'YAML'
id: sparta
display_name: "Sparta Provider"
models:
  - name: leonidas-v1
    model_id: leonidas-v1-20260517
    aliases: ["leonidas", "leo"]
    capabilities:
      closed: ["reasoning"]
      custom: []
capabilities:
  closed: ["reasoning", "tool-use"]
  custom: []
transport:
  streaming: true
  cancellable: true
wire_translator: null
YAML;
    }
}
