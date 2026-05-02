<?php

declare(strict_types=1);

namespace Phalanx\Stoa;

final readonly class StoaServerConfig
{
    public function __construct(
        public string $host = '0.0.0.0',
        public int $port = 8080,
        public float $requestTimeout = 30.0,
        public float $drainTimeout = 30.0,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        return self::fromArray($context);
    }

    /** @param array<string, mixed> $options */
    public static function fromRuntimeOptions(array $options): self
    {
        return self::fromArray($options);
    }

    /** @param array<string, mixed> $values */
    private static function fromArray(array $values): self
    {
        return new self(
            host: self::stringValue($values, ['host', 'PHALANX_HOST'], '0.0.0.0'),
            port: self::intValue($values, ['port', 'PHALANX_PORT'], 8080),
            requestTimeout: self::floatValue($values, ['request_timeout', 'PHALANX_REQUEST_TIMEOUT'], 30.0),
            drainTimeout: self::floatValue($values, ['drain_timeout', 'PHALANX_DRAIN_TIMEOUT'], 30.0),
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function stringValue(array $values, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return (string) $values[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function intValue(array $values, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return (int) $values[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $keys
     */
    private static function floatValue(array $values, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                return (float) $values[$key];
            }
        }

        return $default;
    }
}
