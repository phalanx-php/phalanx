<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use InvalidArgumentException;
use Phalanx\Boot\AppContext;

final class RedisConfig
{
    public function __construct(
        public private(set) string $host = '127.0.0.1',
        public private(set) int $port = 6379,
        public private(set) ?string $username = null,
        public private(set) ?string $password = null,
        public private(set) int $database = 0,
        public private(set) float $connectTimeout = 5.0,
        public private(set) float $readTimeout = 30.0,
        public private(set) int $poolSize = 16,
    ) {
    }

    public static function fromUrl(string $url): self
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new InvalidArgumentException('Redis URL could not be parsed.');
        }

        $scheme = $parts['scheme'] ?? null;
        if ($scheme !== 'redis' || !isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException('Redis URL must use redis:// with a host.');
        }

        return new self(
            host: $parts['host'],
            port: $parts['port'] ?? 6379,
            username: isset($parts['user']) && $parts['user'] !== '' ? urldecode($parts['user']) : null,
            password: isset($parts['pass']) ? urldecode($parts['pass']) : null,
            database: isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0,
        );
    }

    public static function fromContext(AppContext $context): self
    {
        $url = self::nullableStringValue($context->values, ['redis_url', 'REDIS_URL']);
        if ($url !== null) {
            return self::fromUrl($url);
        }

        return new self(
            host: self::stringValue($context->values, ['redis_host', 'REDIS_HOST'], '127.0.0.1'),
            port: self::intValue($context->values, ['redis_port', 'REDIS_PORT'], 6379),
            username: self::nullableStringValue($context->values, ['redis_username', 'REDIS_USERNAME']),
            password: self::nullableStringValue($context->values, ['redis_password', 'REDIS_PASSWORD']),
            database: self::intValue($context->values, ['redis_database', 'REDIS_DATABASE'], 0),
            poolSize: self::intValue($context->values, ['redis_pool_size', 'REDIS_POOL_SIZE'], 16),
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $keys
     */
    private static function stringValue(array $context, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $context)) {
                return (string) $context[$key];
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $keys
     */
    private static function nullableStringValue(array $context, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }

            $value = $context[$key];
            if ($value === null || $value === '') {
                return null;
            }

            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     * @param list<string> $keys
     */
    private static function intValue(array $context, array $keys, int $default): int
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $context)) {
                return (int) $context[$key];
            }
        }

        return $default;
    }
}
