<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * Typed accessor for a HomeDir's settings (sidecar + in-dir merged).
 * Implementations validate shape at construction; the typed `as*()`
 * accessors throw {@see SettingsError} on missing or mistyped keys
 * (fail-loud policy). Use `getOr*()` variants for nullable lookup.
 */
interface Settings
{
    public function has(string $key): bool;

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
}
