<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * Typed accessor for a HomeDir's settings (sidecar + in-dir merged).
 * Implementations validate shape at construction; the typed `as*()`
 * accessors throw {@see SettingsError} on missing or mistyped keys
 * (fail-loud policy). Use `get*()` variants for nullable lookup with
 * an optional default; `as*()` variants throw on missing or mistyped keys.
 *
 * {@see self::isAvailable()} surfaces whether this implementation is wired
 * against a real configuration source. Returns true when settings are
 * readable; returns false in degraded or fallback mode (for example,
 * Codex when no TOML parser is installed).
 */
interface Settings
{
    public function has(string $key): bool;

    /**
     * Returns true when this Settings instance is wired against a real
     * configuration source. Returns false in degraded/fallback mode
     * (e.g., Codex when no TOML parser is installed).
     */
    public function isAvailable(): bool;

    public function asString(string $key): string;

    public function getString(string $key, ?string $default = null): ?string;

    public function asInt(string $key): int;

    public function getInt(string $key, ?int $default = null): ?int;

    public function asBool(string $key): bool;

    public function getBool(string $key, ?bool $default = null): ?bool;

    /**
     * @return array<int|string, mixed>
     */
    public function asArray(string $key): array;

    /**
     * @param array<int|string, mixed>|null $default
     * @return array<int|string, mixed>|null
     */
    public function getArray(string $key, ?array $default = null): ?array;
}
