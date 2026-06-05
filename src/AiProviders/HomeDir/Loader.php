<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir;

use Symfony\Component\Yaml\Yaml;

/**
 * YAML loading and validation for HomeDir adapter config documents.
 * Static-only utility — no instance state.
 *
 * {@see self::fromFile()} validates the parsed document against the required
 * structure and accumulates ALL violations before throwing
 * {@see ValidationError}, so callers see the complete error surface in one pass.
 *
 * Validation rules:
 * - Required top-level keys: id, display_name, roots, adapter
 * - No additional top-level keys (fail-loud on unknown fields)
 * - id: non-empty string matching pattern [a-z][a-z0-9_]*
 * - display_name: non-empty string
 * - roots: non-empty list of strings
 * - adapter: non-empty string (class name, existence not verified here)
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
            throw new \InvalidArgumentException("HomeDir config file not found: {$path}");
        }

        $yaml = file_get_contents($path);

        if ($yaml === false) {
            throw new \RuntimeException("Failed to read HomeDir config file: {$path}");
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
        $required = ['id', 'display_name', 'roots', 'adapter'];

        foreach ($required as $key) {
            if (!array_key_exists($key, $data)) {
                $violations[] = "Missing required key: {$key}";
            }
        }

        foreach (array_keys($data) as $key) {
            if (!in_array($key, $required, strict: true)) {
                $violations[] = "Unknown key: {$key}";
            }
        }

        if (array_key_exists('id', $data)) {
            if (!is_string($data['id']) || $data['id'] === '') {
                $violations[] = "id must be a non-empty string";
            } elseif (!preg_match('/^[a-z][a-z0-9_]*$/', $data['id'])) {
                $violations[] = "id must match pattern [a-z][a-z0-9_]*";
            }
        }

        if (array_key_exists('display_name', $data)) {
            if (!is_string($data['display_name']) || $data['display_name'] === '') {
                $violations[] = "display_name must be a non-empty string";
            }
        }

        if (array_key_exists('roots', $data)) {
            if (!is_array($data['roots']) || count($data['roots']) === 0) {
                $violations[] = "roots must be a non-empty list";
            } else {
                foreach ($data['roots'] as $i => $root) {
                    if (!is_string($root) || $root === '') {
                        $violations[] = "roots[{$i}] must be a non-empty string";
                    }
                }
            }
        }

        if (array_key_exists('adapter', $data)) {
            if (!is_string($data['adapter']) || $data['adapter'] === '') {
                $violations[] = "adapter must be a non-empty string";
            }
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $data pre-validated document
     */
    private static function buildConfig(array $data): Config
    {
        /** @var list<string> $roots */
        $roots = array_values(array_map(strval(...), (array) $data['roots']));

        /** @var class-string<\Phalanx\AiProviders\HomeDir> $adapter */
        $adapter = (string) $data['adapter'];

        return Config::of(
            id: (string) $data['id'],
            displayName: (string) $data['display_name'],
            roots: $roots,
            adapter: $adapter,
        );
    }
}
