<?php

declare(strict_types=1);

namespace Phalanx\Surreal;

use Phalanx\Boot\AppContext;
use Phalanx\Boot\ContextKey;
use Phalanx\Boot\ContextSchema;
use Phalanx\Themis\Config;
use Phalanx\Themis\ConfigFactory;
use Phalanx\Themis\Env;
use Phalanx\Themis\Issue;
use Phalanx\Themis\IssueLevel;
use Phalanx\Themis\ValidationContext;

final class SurrealConfig implements Config
{
    private const string DEFAULT_NAMESPACE = 'phalanx';
    private const string DEFAULT_DATABASE = 'app';
    private const string DEFAULT_ENDPOINT = 'http://127.0.0.1:8000';
    private const float DEFAULT_CONNECT_TIMEOUT = 5.0;
    private const float DEFAULT_READ_TIMEOUT = 30.0;
    private const int DEFAULT_MAX_RESPONSE_BYTES = 16 * 1024 * 1024;

    /** Computed from the minimum values needed to address a Surreal database. */
    public bool $configured {
        get => $this->namespace !== '' && $this->database !== '' && $this->endpoint !== '';
    }

    public function __construct(
        #[Env(key: 'SURREAL_NAMESPACE', description: 'SurrealDB namespace')]
        private(set) string $namespace = self::DEFAULT_NAMESPACE,
        #[Env(key: 'SURREAL_DATABASE', description: 'SurrealDB database')]
        private(set) string $database = self::DEFAULT_DATABASE,
        #[Env(key: 'SURREAL_ENDPOINT', description: 'SurrealDB HTTP endpoint')]
        private(set) string $endpoint = self::DEFAULT_ENDPOINT,
        #[Env(key: 'SURREAL_WS_ENDPOINT', description: 'SurrealDB WebSocket endpoint')]
        private(set) ?string $websocketEndpoint = null,
        #[Env(key: 'SURREAL_USERNAME', description: 'SurrealDB username')]
        private(set) ?string $username = null,
        #[Env(key: 'SURREAL_PASSWORD', description: 'SurrealDB password', secret: true)]
        private(set) ?string $password = null,
        #[Env(key: 'SURREAL_TOKEN', description: 'SurrealDB authentication token', secret: true)]
        private(set) ?string $token = null,
        #[Env(key: 'SURREAL_CONNECT_TIMEOUT', description: 'SurrealDB connect timeout in seconds')]
        private(set) float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        #[Env(key: 'SURREAL_READ_TIMEOUT', description: 'SurrealDB read timeout in seconds')]
        private(set) float $readTimeout = self::DEFAULT_READ_TIMEOUT,
        #[Env(key: 'SURREAL_MAX_RESPONSE_BYTES', description: 'SurrealDB maximum response bytes')]
        private(set) int $maxResponseBytes = self::DEFAULT_MAX_RESPONSE_BYTES,
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->websocketEndpoint = rtrim($websocketEndpoint ?? self::deriveWebsocketEndpoint($this->endpoint), '/');
    }

    public static function fromContext(AppContext $context): self
    {
        return ConfigFactory::fromContext($context->values)->hydrate(self::class);
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

    /** @return list<Issue> */
    public function validate(ValidationContext $context): array
    {
        $issues = [];

        if ($this->namespace === '') {
            $issues[] = new Issue(
                IssueLevel::Error,
                'surreal.namespace-empty',
                'SurrealDB namespace cannot be empty.',
                envKey: 'SURREAL_NAMESPACE',
                path: 'namespace',
            );
        }

        if ($this->database === '') {
            $issues[] = new Issue(
                IssueLevel::Error,
                'surreal.database-empty',
                'SurrealDB database cannot be empty.',
                envKey: 'SURREAL_DATABASE',
                path: 'database',
            );
        }

        if ($this->token === null && ($this->username === null || $this->password === null)) {
            $issues[] = new Issue(
                IssueLevel::Warning,
                'surreal.auth-missing',
                'SurrealDB auth is not configured; this only works for unauthenticated local instances.',
                envKey: 'SURREAL_TOKEN',
                hint: 'Set SURREAL_TOKEN or SURREAL_USERNAME and SURREAL_PASSWORD for authenticated deployments.',
            );
        }

        return $issues;
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
}
