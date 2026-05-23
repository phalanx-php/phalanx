<?php

declare(strict_types=1);

namespace Phalanx\Panoply\HomeDir;

/**
 * Abstract base for map-backed Settings implementations. Holds the
 * merged settings map and provides all nine typed accessors once so
 * subclasses do not duplicate the accessor boilerplate.
 *
 * Subclasses are responsible for building the map (from JSON, sidecar
 * merge, etc.) and passing it to `parent::__construct($map)`.
 *
 * {@see self::isAvailable()} defaults to `true` — map-backed impls are
 * always wired against a real source (even an empty one). Subclasses
 * that can degrade (e.g., TOML-optional parsers) should override this.
 *
 * NOT final — subclasses extend this to supply their source-loading
 * logic. No additional state or method overrides are expected beyond
 * the constructor.
 */
abstract class AbstractMapSettings implements Settings
{
    /**
     * @param array<string, mixed> $map the fully-resolved settings map,
     *        pre-loaded and merged by the subclass constructor
     */
    public function __construct(
        private(set) array $map,
    ) {
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->map);
    }

    public function isAvailable(): bool
    {
        return true;
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
        $value = $this->map[$key];

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
        $value = $this->map[$key];

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
        $value = $this->map[$key];

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
        $value = $this->map[$key];

        return is_array($value) ? $value : $default;
    }

    private function requireKey(string $key): mixed
    {
        if (!$this->has($key)) {
            throw SettingsError::missingKey($key);
        }

        return $this->map[$key];
    }
}
