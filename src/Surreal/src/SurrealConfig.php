<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\ContextKey;
use Phalanx\Boot\ContextSchema;

class SurrealConfig
{
    private const string DEFAULT_NAMESPACE = 'phalanx';
    private const string DEFAULT_DATABASE = 'app';
    private const string DEFAULT_ENDPOINT = 'http://127.0.0.1:8000';
    private const float DEFAULT_CONNECT_TIMEOUT = 5.0;
    private const float DEFAULT_READ_TIMEOUT = 30.0;
    private const int DEFAULT_MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    public function __construct(
        private(set) string $namespace,
        private(set) string $database,
        private(set) string $endpoint = self::DEFAULT_ENDPOINT,
        private(set) ?string $websocketEndpoint = null,
        private(set) ?string $username = null,
        private(set) ?string $password = null,
        private(set) ?string $token = null,
        private(set) float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        private(set) float $readTimeout = self::DEFAULT_READ_TIMEOUT,
        private(set) int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->websocketEndpoint = rtrim($websocketEndpoint ?? self::deriveWebsocketEndpoint($this->endpoint), '/');
    }

    public static function fromContext(AppContext $context): self
    {
        return new self(
            namespace: self::stringValue($context->values, ['surreal_namespace', 'SURREAL_NAMESPACE'], self::DEFAULT_NAMESPACE),
            database: self::stringValue($context->values, ['surreal_database', 'SURREAL_DATABASE'], self::DEFAULT_DATABASE),
            endpoint: self::stringValue($context->values, ['surreal_endpoint', 'SURREAL_ENDPOINT'], self::DEFAULT_ENDPOINT),
            websocketEndpoint: self::nullableStringValue($context->values, ['surreal_ws_endpoint', 'SURREAL_WS_ENDPOINT']),
            username: self::nullableStringValue($context->values, ['surreal_username', 'SURREAL_USERNAME']),
            password: self::nullableStringValue($context->values, ['surreal_password', 'SURREAL_PASSWORD']),
            token: self::nullableStringValue($context->values, ['surreal_token', 'SURREAL_TOKEN']),
            connectTimeout: self::floatValue(
                $context->values,
                ['surreal_connect_timeout', 'SURREAL_CONNECT_TIMEOUT'],
                self::DEFAULT_CONNECT_TIMEOUT,
            ),
            readTimeout: self::floatValue(
                $context->values,
                ['surreal_read_timeout', 'SURREAL_READ_TIMEOUT'],
                self::DEFAULT_READ_TIMEOUT,
            ),
            maxResponseBytes: self::intValue(
                $context->values,
                ['surreal_max_response_bytes', 'SURREAL_MAX_RESPONSE_BYTES'],
                self::DEFAULT_MAX_RESPONSE_BYTES,
            ),
        );
    }

    public static function contextSchema(): ContextSchema
    {
        return ContextSchema::of(
            ContextKey::optional('SURREAL_ENDPOINT', self::DEFAULT_ENDPOINT, 'SurrealDB HTTP endpoint', 'string'),
            ContextKey::optional('SURREAL_WS_ENDPOINT', description: 'SurrealDB WebSocket endpoint', type: 'string'),
            ContextKey::optional('SURREAL_NAMESPACE', self::DEFAULT_NAMESPACE, 'SurrealDB namespace', 'string'),
            ContextKey::optional('SURREAL_DATABASE', self::DEFAULT_DATABASE, 'SurrealDB database', 'string'),
            ContextKey::optional('SURREAL_USERNAME', description: 'SurrealDB username', type: 'string'),
            ContextKey::optional('SURREAL_PASSWORD', description: 'SurrealDB password', type: 'string'),
            ContextKey::optional('SURREAL_TOKEN', description: 'SurrealDB authentication token', type: 'string'),
            ContextKey::optional(
                'SURREAL_CONNECT_TIMEOUT',
                (string) self::DEFAULT_CONNECT_TIMEOUT,
                'SurrealDB connect timeout in seconds',
                'float',
            ),
            ContextKey::optional(
                'SURREAL_READ_TIMEOUT',
                (string) self::DEFAULT_READ_TIMEOUT,
                'SurrealDB read timeout in seconds',
                'float',
            ),
            ContextKey::optional(
                'SURREAL_MAX_RESPONSE_BYTES',
                (string) self::DEFAULT_MAX_RESPONSE_BYTES,
                'SurrealDB maximum response bytes',
                'int',
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
