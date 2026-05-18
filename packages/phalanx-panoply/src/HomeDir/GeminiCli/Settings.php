<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\GeminiCli;

use Phalanx\Panoply\HomeDir\Settings as SettingsInterface;
use Phalanx\Panoply\HomeDir\SettingsError;

/**
 * Settings accessor for Gemini CLI's single settings file
 * (`~/.gemini/settings.json`). There is no sidecar concept in Gemini CLI;
 * a single JSON file holds all configuration.
 *
 * Construction reads and parses the file immediately; subsequent accessor
 * calls are pure lookups.
 *
 * Final — Settings implementations are sealed per vendor.
 */
final class Settings implements SettingsInterface
{
    /** @var array<string, mixed> */
    private(set) array $data;

    public function __construct(private(set) ?string $settingsPath)
    {
        $this->data = $this->readJson($settingsPath);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function asString(string $key): string
    {
        $value = $this->requireKey($key);

        if (!is_string($value)) {
            throw SettingsError::typeMismatch($key, 'string', gettype($value));
        }

        return $value;
    }

    public function getString(string $key, ?string $default = null): ?string
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->data[$key];

        return is_string($value) ? $value : $default;
    }

    public function asInt(string $key): int
    {
        $value = $this->requireKey($key);

        if (!is_int($value)) {
            throw SettingsError::typeMismatch($key, 'int', gettype($value));
        }

        return $value;
    }

    public function getInt(string $key, ?int $default = null): ?int
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->data[$key];

        return is_int($value) ? $value : $default;
    }

    public function asBool(string $key): bool
    {
        $value = $this->requireKey($key);

        if (!is_bool($value)) {
            throw SettingsError::typeMismatch($key, 'bool', gettype($value));
        }

        return $value;
    }

    public function getBool(string $key, ?bool $default = null): ?bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->data[$key];

        return is_bool($value) ? $value : $default;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function asArray(string $key): array
    {
        $value = $this->requireKey($key);

        if (!is_array($value)) {
            throw SettingsError::typeMismatch($key, 'array', gettype($value));
        }

        return $value;
    }

    /**
     * @param array<int|string, mixed>|null $default
     * @return array<int|string, mixed>|null
     */
    public function getArray(string $key, ?array $default = null): ?array
    {
        if (!$this->has($key)) {
            return $default;
        }
        $value = $this->data[$key];

        return is_array($value) ? $value : $default;
    }

    private function requireKey(string $key): mixed
    {
        if (!$this->has($key)) {
            throw SettingsError::missingKey($key, 'gemini_cli');
        }

        return $this->data[$key];
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(?string $path): array
    {
        if ($path === null || !is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, associative: true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
