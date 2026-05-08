<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

class SurrealConfig
{
    public function __construct(
        public private(set) string $namespace,
        public private(set) string $database,
        public private(set) string $endpoint = 'http://127.0.0.1:8000',
        public private(set) ?string $websocketEndpoint = null,
        public private(set) ?string $username = null,
        public private(set) ?string $password = null,
        public private(set) ?string $token = null,
        public private(set) float $connectTimeout = 5.0,
        public private(set) float $readTimeout = 30.0,
        public private(set) int $maxResponseBytes = 16 * 1024 * 1024,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->websocketEndpoint = rtrim($websocketEndpoint ?? self::deriveWebsocketEndpoint($this->endpoint), '/');
    }

    /** @param array<string, mixed> $context */
    public static function fromContext(array $context): self
    {
        return new self(
            namespace: self::stringValue($context, ['surreal_namespace', 'SURREAL_NAMESPACE'], 'phalanx'),
            database: self::stringValue($context, ['surreal_database', 'SURREAL_DATABASE'], 'app'),
            endpoint: self::stringValue($context, ['surreal_endpoint', 'SURREAL_ENDPOINT'], 'http://127.0.0.1:8000'),
            websocketEndpoint: self::nullableStringValue($context, ['surreal_ws_endpoint', 'SURREAL_WS_ENDPOINT']),
            username: self::nullableStringValue($context, ['surreal_username', 'SURREAL_USERNAME']),
            password: self::nullableStringValue($context, ['surreal_password', 'SURREAL_PASSWORD']),
            token: self::nullableStringValue($context, ['surreal_token', 'SURREAL_TOKEN']),
            connectTimeout: self::floatValue($context, ['surreal_connect_timeout', 'SURREAL_CONNECT_TIMEOUT'], 5.0),
            readTimeout: self::floatValue($context, ['surreal_read_timeout', 'SURREAL_READ_TIMEOUT'], 30.0),
            maxResponseBytes: self::intValue(
                $context,
                ['surreal_max_response_bytes', 'SURREAL_MAX_RESPONSE_BYTES'],
                16 * 1024 * 1024,
            ),
        );
    }

    public function withDatabase(string $namespace, string $database): self
    {
        return new self(
            namespace: $namespace,
            database: $database,
            endpoint: $this->endpoint,
            websocketEndpoint: $this->websocketEndpoint,
            username: $this->username,
            password: $this->password,
            token: $this->token,
            connectTimeout: $this->connectTimeout,
            readTimeout: $this->readTimeout,
            maxResponseBytes: $this->maxResponseBytes,
        );
    }

    private static function deriveWebsocketEndpoint(string $endpoint): string
    {
        if (str_starts_with($endpoint, 'https://')) {
            return 'wss://' . substr($endpoint, 8) . '/rpc';
        }

        if (str_starts_with($endpoint, 'http://')) {
            return 'ws://' . substr($endpoint, 7) . '/rpc';
        }

        return $endpoint;
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
    private static function floatValue(array $context, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $context)) {
                return (float) $context[$key];
            }
        }

        return $default;
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
