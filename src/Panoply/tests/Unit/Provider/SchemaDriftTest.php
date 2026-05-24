<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Tests\Unit\Provider;

use Phalanx\Panoply\Provider\Loader;
use Phalanx\Panoply\Provider\ValidationError;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Detects drift between schema.json declarations and Loader::validateDocument()
 * enforcement. Each test constructs a bad-YAML scenario — a missing required
 * key or an unknown key at a specific nesting level — and asserts that Loader
 * surfaces a ValidationError. If schema.json adds a required key that Loader
 * does not enforce, these tests catch it before it reaches production.
 */
final class SchemaDriftTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Required-key lists mirrored from schema.json.
    // Any change here must be paired with a matching Loader change.
    // -------------------------------------------------------------------------

    /** @var list<string> */
    private const array TOP_REQUIRED = ['id', 'display_name', 'models', 'capabilities', 'transport', 'wire_translator'];

    /** @var list<string> */
    private const array MODEL_REQUIRED = ['name', 'model_id', 'aliases', 'capabilities'];

    /** @var list<string> */
    private const array CAPS_REQUIRED = ['closed', 'custom'];

    /** @var list<string> */
    private const array TRANSPORT_REQUIRED = ['streaming', 'cancellable'];

    // -------------------------------------------------------------------------
    // Top-level required keys
    // -------------------------------------------------------------------------

    #[Test]
    public function topLevelRequiredKeysMatchSchema(): void
    {
        foreach (self::TOP_REQUIRED as $key) {
            $yaml = self::withoutTopKey($key);

            try {
                Loader::fromString($yaml, "missing-{$key}.yaml");
                self::fail("Loader did not reject document missing top-level key '{$key}'");
            } catch (ValidationError $e) {
                self::assertStringContainsString(
                    $key,
                    implode("\n", $e->violations),
                    "Violation for missing '{$key}' must reference the key name",
                );
            }
        }
    }

    #[Test]
    public function topLevelAdditionalPropertiesFalseEnforced(): void
    {
        $yaml = self::baseYaml() . "\nsparta_bonus: true\n";

        $this->expectException(ValidationError::class);

        Loader::fromString($yaml, 'extra-top.yaml');
    }

    // -------------------------------------------------------------------------
    // Models required keys
    // -------------------------------------------------------------------------

    #[Test]
    public function modelRequiredKeysMatchSchema(): void
    {
        foreach (self::MODEL_REQUIRED as $key) {
            $model = self::modelBlock();
            unset($model[$key]);
            $yaml = self::yamlWithModel($model);

            try {
                Loader::fromString($yaml, "model-missing-{$key}.yaml");
                self::fail("Loader did not reject model entry missing '{$key}'");
            } catch (ValidationError $e) {
                self::assertStringContainsString(
                    $key,
                    implode("\n", $e->violations),
                    "Violation for missing model key '{$key}' must reference the key name",
                );
            }
        }
    }

    #[Test]
    public function modelAdditionalPropertiesFalseEnforced(): void
    {
        $model = self::modelBlock();
        $model['unknown_field'] = true;

        $this->expectException(ValidationError::class);

        Loader::fromString(self::yamlWithModel($model), 'model-extra.yaml');
    }

    // -------------------------------------------------------------------------
    // Capabilities required keys
    // -------------------------------------------------------------------------

    #[Test]
    public function capsRequiredKeysMatchSchema(): void
    {
        foreach (self::CAPS_REQUIRED as $key) {
            $otherKey = $key === 'closed' ? 'custom' : 'closed';
            $yaml = <<<YAML
id: olympus
display_name: "Olympus"
models: []
capabilities:
  {$otherKey}: []
transport:
  streaming: true
  cancellable: true
wire_translator: null
YAML;

            try {
                Loader::fromString($yaml, "caps-missing-{$key}.yaml");
                self::fail("Loader did not reject capabilities missing '{$key}'");
            } catch (ValidationError $e) {
                self::assertStringContainsString(
                    $key,
                    implode("\n", $e->violations),
                    "Violation for missing caps key '{$key}' must reference the key",
                );
            }
        }
    }

    #[Test]
    public function capsAdditionalPropertiesFalseEnforced(): void
    {
        $yaml = <<<'YAML'
id: olympus
display_name: "Olympus"
models: []
capabilities:
  closed: []
  custom: []
  olympian: true
transport:
  streaming: true
  cancellable: true
wire_translator: null
YAML;

        $this->expectException(ValidationError::class);

        Loader::fromString($yaml, 'caps-extra.yaml');
    }

    // -------------------------------------------------------------------------
    // Transport required keys
    // -------------------------------------------------------------------------

    #[Test]
    public function transportRequiredKeysMatchSchema(): void
    {
        foreach (self::TRANSPORT_REQUIRED as $key) {
            $otherKey = $key === 'streaming' ? 'cancellable' : 'streaming';
            $yaml = <<<YAML
id: olympus
display_name: "Olympus"
models: []
capabilities:
  closed: []
  custom: []
transport:
  {$otherKey}: true
wire_translator: null
YAML;

            try {
                Loader::fromString($yaml, "transport-missing-{$key}.yaml");
                self::fail("Loader did not reject transport missing '{$key}'");
            } catch (ValidationError $e) {
                self::assertStringContainsString(
                    $key,
                    implode("\n", $e->violations),
                    "Violation for missing transport key '{$key}' must reference the key",
                );
            }
        }
    }

    #[Test]
    public function transportAdditionalPropertiesFalseEnforced(): void
    {
        $yaml = <<<'YAML'
id: olympus
display_name: "Olympus"
models: []
capabilities:
  closed: []
  custom: []
transport:
  streaming: true
  cancellable: true
  retry_policy: exponential
wire_translator: null
YAML;

        $this->expectException(ValidationError::class);

        Loader::fromString($yaml, 'transport-extra.yaml');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function baseYaml(): string
    {
        return <<<'YAML'
id: olympus
display_name: "Olympus Provider"
models: []
capabilities:
  closed: []
  custom: []
transport:
  streaming: true
  cancellable: true
wire_translator: null
YAML;
    }

    private static function withoutTopKey(string $key): string
    {
        // Build a complete document from explicit key-value pairs, skipping $key.
        $all = [
            'id' => 'id: olympus',
            'display_name' => 'display_name: "Olympus Provider"',
            'models' => 'models: []',
            'capabilities' => "capabilities:\n  closed: []\n  custom: []",
            'transport' => "transport:\n  streaming: true\n  cancellable: true",
            'wire_translator' => 'wire_translator: null',
        ];

        unset($all[$key]);

        return implode("\n", $all) . "\n";
    }

    /**
     * @return array<string, mixed>
     */
    private static function modelBlock(): array
    {
        return [
            'name' => 'zeus-thunderbolt',
            'model_id' => 'zt-1',
            'aliases' => ['zeus'],
            'capabilities' => ['closed' => ['reasoning'], 'custom' => []],
        ];
    }

    /**
     * Build a minimal valid YAML doc with one model entry constructed from
     * the supplied field map. Values are JSON-encoded for inline embedding.
     *
     * @param array<string, mixed> $model
     */
    private static function yamlWithModel(array $model): string
    {
        $lines = ['id: olympus', 'display_name: "Olympus"', 'models:'];

        $lines[] = '  - ' . (isset($model['name']) ? "name: {$model['name']}" : '');

        foreach (['model_id', 'aliases', 'capabilities'] as $field) {
            if (!array_key_exists($field, $model)) {
                continue;
            }

            if ($field === 'capabilities') {
                $caps = $model['capabilities'];
                $lines[] = '    capabilities:';
                $lines[] = '      closed: ' . json_encode($caps['closed'] ?? []);
                $lines[] = '      custom: ' . json_encode($caps['custom'] ?? []);
            } elseif ($field === 'aliases') {
                $lines[] = '    aliases: ' . json_encode($model['aliases']);
            } else {
                $lines[] = "    {$field}: {$model[$field]}";
            }
        }

        if (array_key_exists('unknown_field', $model)) {
            $lines[] = '    unknown_field: true';
        }

        $lines[] = 'capabilities:';
        $lines[] = '  closed: []';
        $lines[] = '  custom: []';
        $lines[] = 'transport:';
        $lines[] = '  streaming: true';
        $lines[] = '  cancellable: true';
        $lines[] = 'wire_translator: null';

        return implode("\n", $lines) . "\n";
    }
}
