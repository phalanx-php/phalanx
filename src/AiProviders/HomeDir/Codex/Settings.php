<?php

declare(strict_types=1);

namespace Phalanx\AiProviders\HomeDir\Codex;

use Phalanx\AiProviders\HomeDir\Settings as SettingsInterface;
use Phalanx\AiProviders\HomeDir\SettingsError;
use PhpCollective\Toml\Toml;

/**
 * Settings accessor for Codex's `config.toml` configuration file.
 *
 * TOML support: PHP has no stable built-in TOML parser in standard
 * distributions. When php-collective/toml is not available, this
 * implementation operates in no-op mode:
 * {@see self::has()} returns `false` for all keys, `get*()` variants
 * return `null` or the supplied default, and `as*()` variants throw
 * {@see SettingsError}. This degraded mode is intentional — Codex settings
 * are not required for conversation log reading.
 *
 * Final — Settings implementations are sealed per vendor.
 */
final class Settings implements SettingsInterface
{
    /** @var array<string, mixed> */
    private(set) array $data;

    /** Whether a TOML parser was available at construction time. */
    private(set) bool $tomlAvailable;

    public function __construct(private(set) ?string $configTomlPath)
    {
        [$this->data, $this->tomlAvailable] = self::loadConfig($configTomlPath);
    }

    public function has(string $key): bool
    {
        if (!$this->tomlAvailable) {
            return false;
        }

        return array_key_exists($key, $this->data);
    }

    public function isAvailable(): bool
    {
        return $this->tomlAvailable;
    }

    public function asString(string $key): string
    {
        $this->requireToml($key);
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
        $this->requireToml($key);
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
        $this->requireToml($key);
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
        $this->requireToml($key);
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

    /**
     * Attempt to load Codex's config.toml. Returns the parsed data array
     * and a flag indicating whether a TOML parser was available.
     *
     * @return array{array<string, mixed>, bool}
     */
    private static function loadConfig(?string $path): array
    {
        if ($path === null || !is_file($path)) {
            return [[], false];
        }

        if (class_exists(Toml::class)) {
            $result = Toml::tryParse(file_get_contents($path) ?: '');
            if ($result->isValid()) {
                /** @var array<string, mixed> $parsed */
                $parsed = $result->getValue() ?? [];

                return [$parsed, true];
            }

            return [[], true];
        }

        // No TOML parser available — operate in no-op mode.
        return [[], false];
    }

    private function requireKey(string $key): mixed
    {
        if (!array_key_exists($key, $this->data)) {
            throw SettingsError::missingKey($key, 'codex');
        }

        return $this->data[$key];
    }

    private function requireToml(string $key): void
    {
        if (!$this->tomlAvailable) {
            throw new SettingsError(
                "Codex settings are unavailable: no TOML parser found. " .
                "Install a compatible TOML library to read '{$key}'.",
            );
        }
    }
}
