<?php

declare(strict_types=1);

namespace Phalanx\Panoply\Provider;

use Phalanx\Panoply\Capabilities;
use Phalanx\Panoply\Capability;
use Phalanx\Panoply\Transport\Needs as TransportNeeds;
use Symfony\Component\Yaml\Yaml;

/**
 * YAML loading and validation for provider config documents. Static-only
 * utility — no instance state.
 *
 * {@see self::fromFile()} and {@see self::fromString()} both validate the
 * parsed document against the required structure and accumulate ALL
 * violations before throwing {@see ValidationError}, so callers see the
 * complete error surface in one pass.
 *
 * YAML co-location convention: vendor-specific YAMLs live in their vendor
 * namespace directory (e.g., `Provider/HuggingFace/huggingface-dedicated.panoply.yaml`)
 * even when they reuse a shared `wire_translator` (e.g., `OpenAI\ChatProvider`).
 * The `Provider/OpenAICompatible/` umbrella is the exception — it covers
 * providers that are wire-equivalent to OpenAI and have no vendor-specific tier.
 *
 * Validation rules:
 * - Required top-level keys: id, display_name, models, capabilities,
 *   transport, wire_translator
 * - No additional top-level keys (fail-loud on unknown fields)
 * - id, display_name: non-empty string
 * - models: list of objects, each with required name, model_id, aliases,
 *   capabilities; optional input_pricing, output_pricing
 * - capabilities: object with closed (list) and custom (list)
 * - transport: object with streaming (bool), cancellable (bool);
 *   optional backpressure (bool), partial_json (bool)
 * - wire_translator: string or null; when a string and the class does
 *   not exist in this environment, the field resolves to null in the
 *   resulting Config (soft-dep policy)
 *
 * Final — no extension points; validation rules are a closed contract.
 */
final class Loader
{
    private function __construct()
    {
    }

    public static function fromFile(string $path): Config
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("Provider config file not found: {$path}");
        }

        $yaml = file_get_contents($path);

        if ($yaml === false) {
            throw new \RuntimeException("Failed to read provider config file: {$path}");
        }

        return self::fromString($yaml, $path);
    }

    public static function fromString(string $yaml, string $sourceLabel = '<inline>'): Config
    {
        $data = Yaml::parse($yaml);

        if (!is_array($data)) {
            throw new ValidationError(['Document root must be a mapping'], $sourceLabel);
        }

        $violations = self::validateDocument($data);

        if ($violations !== []) {
            throw new ValidationError($violations, $sourceLabel);
        }

        return self::buildConfig($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function validateDocument(array $data): array
    {
        $violations = [];

        $required = ['id', 'display_name', 'models', 'capabilities', 'transport', 'wire_translator'];
        $allowed = array_merge($required, ['base_url', 'default_headers']);

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                $violations[] = "Missing required key: {$key}";
            }
        }

        foreach (array_keys($data) as $key) {
            if (!in_array($key, $allowed, strict: true)) {
                $violations[] = "Unknown key: {$key}";
            }
        }

        if (array_key_exists('id', $data)) {
            if (!is_string($data['id']) || $data['id'] === '') {
                $violations[] = "id must be a non-empty string";
            }
        }

        if (array_key_exists('display_name', $data)) {
            if (!is_string($data['display_name']) || $data['display_name'] === '') {
                $violations[] = "display_name must be a non-empty string";
            }
        }

        if (array_key_exists('models', $data)) {
            $violations = array_merge($violations, self::validateModels($data['models']));
        }

        if (array_key_exists('capabilities', $data)) {
            $violations = array_merge($violations, self::validateCapabilities($data['capabilities'], 'capabilities'));
        }

        if (array_key_exists('transport', $data)) {
            $violations = array_merge($violations, self::validateTransport($data['transport']));
        }

        if (array_key_exists('wire_translator', $data)) {
            $wt = $data['wire_translator'];
            if ($wt !== null && !is_string($wt)) {
                $violations[] = "wire_translator must be a string or null";
            }
        }

        if (array_key_exists('base_url', $data)) {
            if (!is_string($data['base_url'])) {
                $violations[] = "base_url must be a string";
            }
        }

        if (array_key_exists('default_headers', $data)) {
            $violations = array_merge($violations, self::validateDefaultHeaders($data['default_headers']));
        }

        return $violations;
    }

    /**
     * @param mixed $models
     * @return list<string>
     */
    private static function validateModels(mixed $models): array
    {
        if (!is_array($models)) {
            return ['models must be a list'];
        }

        $violations = [];

        foreach ($models as $i => $model) {
            $prefix = "models[{$i}]";

            if (!is_array($model)) {
                $violations[] = "{$prefix} must be a mapping";
                continue;
            }

            $requiredModelKeys = ['name', 'model_id', 'aliases', 'capabilities'];

            foreach ($requiredModelKeys as $key) {
                if (!array_key_exists($key, $model)) {
                    $violations[] = "{$prefix}: missing required key: {$key}";
                }
            }

            if (array_key_exists('name', $model) && (!is_string($model['name']) || $model['name'] === '')) {
                $violations[] = "{$prefix}.name must be a non-empty string";
            }

            if (array_key_exists('model_id', $model) && (!is_string($model['model_id']) || $model['model_id'] === '')) {
                $violations[] = "{$prefix}.model_id must be a non-empty string";
            }

            if (array_key_exists('aliases', $model)) {
                if (!is_array($model['aliases'])) {
                    $violations[] = "{$prefix}.aliases must be a list";
                } else {
                    foreach ($model['aliases'] as $j => $alias) {
                        if (!is_string($alias)) {
                            $violations[] = "{$prefix}.aliases[{$j}] must be a string";
                        }
                    }
                }
            }

            if (array_key_exists('capabilities', $model)) {
                $violations = array_merge(
                    $violations,
                    self::validateCapabilities($model['capabilities'], "{$prefix}.capabilities"),
                );
            }

            if (array_key_exists('input_pricing', $model)) {
                $ip = $model['input_pricing'];
                if ($ip !== null && !is_float($ip) && !is_int($ip)) {
                    $violations[] = "{$prefix}.input_pricing must be a number or null";
                }
            }

            if (array_key_exists('output_pricing', $model)) {
                $op = $model['output_pricing'];
                if ($op !== null && !is_float($op) && !is_int($op)) {
                    $violations[] = "{$prefix}.output_pricing must be a number or null";
                }
            }

            $allowedModelKeys = ['name', 'model_id', 'aliases', 'capabilities', 'input_pricing', 'output_pricing'];
            foreach (array_keys($model) as $key) {
                if (!in_array($key, $allowedModelKeys, strict: true)) {
                    $violations[] = "{$prefix}: unknown key '{$key}'";
                }
            }
        }

        return $violations;
    }

    /**
     * @param mixed  $caps
     * @param string $path for violation messages
     * @return list<string>
     */
    private static function validateCapabilities(mixed $caps, string $path): array
    {
        if (!is_array($caps)) {
            return ["{$path} must be a mapping"];
        }

        $violations = [];

        if (!array_key_exists('closed', $caps)) {
            $violations[] = "{$path}: missing required key: closed";
        } elseif (!is_array($caps['closed'])) {
            $violations[] = "{$path}.closed must be a list";
        }

        if (!array_key_exists('custom', $caps)) {
            $violations[] = "{$path}: missing required key: custom";
        } elseif (!is_array($caps['custom'])) {
            $violations[] = "{$path}.custom must be a list";
        }

        $allowedCapsKeys = ['closed', 'custom'];
        foreach (array_keys($caps) as $key) {
            if (!in_array($key, $allowedCapsKeys, strict: true)) {
                $violations[] = "{$path}: unknown key '{$key}'";
            }
        }

        return $violations;
    }

    /**
     * @param mixed $headers
     * @return list<string>
     */
    private static function validateDefaultHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return ['default_headers must be a mapping'];
        }

        $violations = [];

        foreach ($headers as $key => $value) {
            if (!is_string($value)) {
                $violations[] = "default_headers.{$key} must be a string";
            }
        }

        return $violations;
    }

    /**
     * @param mixed $transport
     * @return list<string>
     */
    private static function validateTransport(mixed $transport): array
    {
        if (!is_array($transport)) {
            return ['transport must be a mapping'];
        }

        $violations = [];

        foreach (['streaming', 'cancellable'] as $key) {
            if (!array_key_exists($key, $transport)) {
                $violations[] = "transport: missing required key: {$key}";
            } elseif (!is_bool($transport[$key])) {
                $violations[] = "transport.{$key} must be a boolean";
            }
        }

        foreach (['backpressure', 'partial_json'] as $key) {
            if (array_key_exists($key, $transport) && !is_bool($transport[$key])) {
                $violations[] = "transport.{$key} must be a boolean";
            }
        }

        $allowedTransportKeys = ['streaming', 'cancellable', 'backpressure', 'partial_json'];
        foreach (array_keys($transport) as $key) {
            if (!in_array($key, $allowedTransportKeys, strict: true)) {
                $violations[] = "transport: unknown key '{$key}'";
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $data pre-validated document
     */
    private static function buildConfig(array $data): Config
    {
        $models = array_map(
            self::buildModel(...),
            (array) $data['models'],
        );

        $capabilities = self::buildCapabilities((array) $data['capabilities']);
        $transport = self::buildTransport((array) $data['transport']);

        $wireTranslator = $data['wire_translator'];
        if (is_string($wireTranslator) && !class_exists($wireTranslator)) {
            $wireTranslator = null;
        }

        $baseUrl = isset($data['base_url']) && is_string($data['base_url'])
            ? $data['base_url']
            : null;

        /** @var array<string, string> $defaultHeaders */
        $defaultHeaders = [];
        if (isset($data['default_headers']) && is_array($data['default_headers'])) {
            foreach ($data['default_headers'] as $k => $v) {
                $defaultHeaders[(string) $k] = (string) $v;
            }
        }

        /** @var class-string<\Phalanx\Panoply\Provider>|null $wireTranslator */
        return Config::of(
            id: (string) $data['id'],
            displayName: (string) $data['display_name'],
            models: array_values($models),
            capabilities: $capabilities,
            transport: $transport,
            wireTranslator: $wireTranslator,
            baseUrl: $baseUrl,
            defaultHeaders: $defaultHeaders,
        );
    }

    /**
     * @param array<string, mixed> $m
     */
    private static function buildModel(array $m): Config\Model
    {
        $aliases = array_values(array_map(strval(...), (array) $m['aliases']));
        $capabilities = self::buildCapabilities((array) $m['capabilities']);

        $inputPricing = isset($m['input_pricing']) ? (float) $m['input_pricing'] : null;
        $outputPricing = isset($m['output_pricing']) ? (float) $m['output_pricing'] : null;

        return Config\Model::of(
            name: (string) $m['name'],
            modelId: (string) $m['model_id'],
            aliases: $aliases,
            capabilities: $capabilities,
            inputPricing: $inputPricing,
            outputPricing: $outputPricing,
        );
    }

    /**
     * @param array<string, mixed> $caps
     */
    private static function buildCapabilities(array $caps): Capabilities
    {
        $closed = array_values(array_map(strval(...), (array) $caps['closed']));
        $custom = array_values(array_map(strval(...), (array) $caps['custom']));

        $cases = [];
        foreach ($closed as $value) {
            $case = Capability::tryFrom($value);
            if ($case !== null) {
                $cases[] = $case;
            }
        }

        return new Capabilities($cases, $custom);
    }

    /**
     * @param array<string, mixed> $transport
     */
    private static function buildTransport(array $transport): TransportNeeds
    {
        $needs = TransportNeeds::new();

        if (($transport['streaming'] ?? false) === true) {
            $needs = $needs->streaming();
        }

        if (($transport['cancellable'] ?? false) === true) {
            $needs = $needs->cancellable();
        }

        if (($transport['backpressure'] ?? false) === true) {
            $needs = $needs->preferBackpressure();
        }

        if (($transport['partial_json'] ?? false) === true) {
            $needs = $needs->preferPartialJson();
        }

        return $needs;
    }
}
