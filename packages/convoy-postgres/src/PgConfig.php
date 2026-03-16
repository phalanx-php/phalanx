<?php

declare(strict_types=1);

namespace Convoy\Postgres;

use Amp\Postgres\PostgresConfig;

final class PgConfig
{
    public function __construct(
        public private(set) string $host = 'localhost',
        public private(set) int $port = 5432,
        public private(set) ?string $user = null,
        public private(set) ?string $password = null,
        public private(set) ?string $database = null,
        public private(set) int $maxConnections = 10,
        public private(set) int $idleTimeout = 60,
        public private(set) ?string $applicationName = 'convoy',
        public private(set) ?string $sslMode = null,
    ) {}

    public static function fromDsn(string $dsn): self
    {
        $parts = parse_url($dsn);

        return new self(
            host: $parts['host'] ?? 'localhost',
            port: $parts['port'] ?? 5432,
            user: isset($parts['user']) ? urldecode($parts['user']) : null,
            password: isset($parts['pass']) ? urldecode($parts['pass']) : null,
            database: isset($parts['path']) ? ltrim(urldecode($parts['path']), '/') : null,
        );
    }

    public function toAmphpConfig(): PostgresConfig
    {
        return new PostgresConfig(
            host: $this->host,
            port: $this->port,
            user: $this->user,
            password: $this->password,
            database: $this->database,
            applicationName: $this->applicationName,
            sslMode: $this->sslMode,
        );
    }
}
