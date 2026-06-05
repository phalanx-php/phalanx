<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\Tests\Unit\Provider\Config;

use Phalanx\AiProviders\Capabilities;
use Phalanx\AiProviders\Capability;
use Phalanx\AiProviders\Hash\Canonical;
use Phalanx\AiProviders\Provider\Config\Model;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ModelTest extends TestCase
{
    #[Test]
    public function ofConstructsWithAllFields(): void
    {
        $model = self::fixture();

        self::assertSame('claude-opus-4-7', $model->name);
        self::assertSame('claude-opus-4-7-20250514', $model->modelId);
        self::assertSame(['opus', 'opus-4', 'opus-latest'], $model->aliases);
        self::assertSame(0.003, $model->inputPricing);
        self::assertSame(0.015, $model->outputPricing);
    }

    #[Test]
    public function toCanonicalHasExpectedKeys(): void
    {
        $canonical = self::fixture()->toCanonical();

        self::assertArrayHasKey('name', $canonical);
        self::assertArrayHasKey('model_id', $canonical);
        self::assertArrayHasKey('aliases', $canonical);
        self::assertArrayHasKey('capabilities', $canonical);
        self::assertArrayHasKey('input_pricing', $canonical);
        self::assertArrayHasKey('output_pricing', $canonical);
    }

    #[Test]
    public function toCanonicalSortsAliasesForDeterminism(): void
    {
        $model = Model::of(
            name: 'apollo',
            modelId: 'apollo-v1',
            aliases: ['zeus', 'ares', 'hestia'],
            capabilities: Capabilities::empty(),
        );

        $canonical = $model->toCanonical();

        self::assertSame(['ares', 'hestia', 'zeus'], $canonical['aliases']);
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
    public function canonicalAlgorithmAnchorForModel(): void
    {
        self::assertSame(
            '9488834bc5cd4f22881237a06fff8da08a730d9b17659592398f99c67b85c0ef',
            Canonical::of(self::fixture()),
        );
    }

    #[Test]
    public function nullPricingIsPreserved(): void
    {
        $model = Model::of('leonidas', 'leonidas-v1', [], Capabilities::empty());
        $canonical = $model->toCanonical();

        self::assertNull($canonical['input_pricing']);
        self::assertNull($canonical['output_pricing']);
    }

    private static function fixture(): Model
    {
        return Model::of(
            name: 'claude-opus-4-7',
            modelId: 'claude-opus-4-7-20250514',
            aliases: ['opus', 'opus-4', 'opus-latest'],
            capabilities: Capabilities::of(Capability::Reasoning, Capability::ToolUse),
            inputPricing: 0.003,
            outputPricing: 0.015,
        );
    }
}
