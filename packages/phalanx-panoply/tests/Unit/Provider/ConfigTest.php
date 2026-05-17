<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Hash\Canonical;
use Phalanx\Panoply\Provider\Config;
use Phalanx\Panoply\Provider\Config\Model;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    #[Test]
    public function ofConstructsWithAllFields(): void
    {
        $config = self::fixture();

        self::assertSame('olympus', $config->id);
        self::assertSame('Olympus Provider', $config->displayName);
        self::assertCount(1, $config->models);
        self::assertNull($config->wireTranslator);
    }

    #[Test]
    public function toCanonicalHasExpectedKeys(): void
    {
        $canonical = self::fixture()->toCanonical();

        self::assertArrayHasKey('id', $canonical);
        self::assertArrayHasKey('display_name', $canonical);
        self::assertArrayHasKey('models', $canonical);
        self::assertArrayHasKey('capabilities', $canonical);
        self::assertArrayHasKey('transport', $canonical);
        self::assertArrayHasKey('wire_translator', $canonical);
    }

    #[Test]
    public function toCanonicalIncludesNestedModelCanonical(): void
    {
        $canonical = self::fixture()->toCanonical();

        self::assertIsArray($canonical['models']);
        self::assertCount(1, $canonical['models']);
        self::assertArrayHasKey('name', $canonical['models'][0]);
    }

    #[Test]
    public function hashDeterminism(): void
    {
        $a = self::fixture();
        $b = self::fixture();

        self::assertSame(Canonical::of($a), Canonical::of($b));
    }

    #[Test]
    public function hashIs64CharHex(): void
    {
        $hash = Canonical::of(self::fixture());

        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        self::assertSame(64, strlen($hash));
    }

    #[Test]
    public function canonicalAlgorithmAnchorForConfig(): void
    {
        self::assertSame(
            '5458e0697b7e0d686cb25e38c7f6cd587c033afbd1f089e24655126cca1ab7a3',
            Canonical::of(self::fixture()),
        );
    }

    #[Test]
    public function wireTranslatorCanBeNonNull(): void
    {
        $config = Config::of(
            id: 'sparta',
            displayName: 'Sparta Provider',
            models: [],
            capabilities: Capabilities::empty(),
            transport: TransportNeeds::new(),
            wireTranslator: null,
        );

        self::assertNull($config->wireTranslator);
    }

    private static function fixture(): Config
    {
        $model = Model::of(
            name: 'apollo-v1',
            modelId: 'apollo-v1-20260101',
            aliases: ['apollo', 'light-bringer'],
            capabilities: Capabilities::of(Capability::Reasoning),
        );

        return Config::of(
            id: 'olympus',
            displayName: 'Olympus Provider',
            models: [$model],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
            transport: TransportNeeds::new()->streaming()->cancellable(),
            wireTranslator: null,
        );
    }
}
