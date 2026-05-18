<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir\ClaudeCode;

use Phalanx\Panoply\HomeDir\Settings as SettingsInterface;
use Phalanx\Panoply\HomeDir\SettingsError;

/**
 * Settings accessor for Claude Code's merged configuration. Reads both a
 * sidecar file (`~/.claude.json`, when present) and an in-directory settings
 * file (`~/.claude/settings.json`, when present), merging them with the
 * in-directory file winning on key conflicts (deep merge — sub-objects are
 * merged key-by-key rather than replaced wholesale).
 *
 * Construction reads and parses both files immediately; subsequent accessor
 * calls are pure lookups into the merged map.
 *
 * Final — Settings implementations are sealed per vendor.
 */
final class Settings implements SettingsInterface
{
    /** @var array<string, mixed> */
    private(set) array $merged;

    public function __construct(
        private(set) ?string $sidecarPath,
        private(set) ?string $inDirPath,
    ) {
        $sidecar = $this->readJson($sidecarPath);
        $inDir   = $this->readJson($inDirPath);

        $this->merged = self::deepMerge($sidecar, $inDir);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->merged);
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
        $value = $this->merged[$key];

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
        $value = $this->merged[$key];

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
        $value = $this->merged[$key];

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
        $value = $this->merged[$key];

        return is_array($value) ? $value : $default;
    }

    /**
     * Deep-merge two maps. Keys in `$override` win on conflict; sub-arrays
     * are merged recursively rather than replaced wholesale.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        $result = $base;

        foreach ($override as $key => $value) {
            if (
                isset($result[$key])
                && is_array($result[$key])
                && is_array($value)
            ) {
                /** @var array<string, mixed> $result[$key] */
                /** @var array<string, mixed> $value */
                $result[$key] = self::deepMerge($result[$key], $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function requireKey(string $key): mixed
    {
        if (!$this->has($key)) {
            throw SettingsError::missingKey($key, 'claude_code');
        }

        return $this->merged[$key];
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
