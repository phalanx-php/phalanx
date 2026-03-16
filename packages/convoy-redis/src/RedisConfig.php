<?php

declare(strict_types=1);

namespace Convoy\Redis;

final class RedisConfig
{
    public function __construct(
        public private(set) string $host = '127.0.0.1',
        public private(set) int $port = 6379,
        public private(set) ?string $password = null,
        public private(set) int $database = 0,
        public private(set) float $idleTimeout = 60.0,
    ) {}

    public static function fromUrl(string $url): self
    {
        $parts = parse_url($url);

        return new self(
            host: $parts['host'] ?? '127.0.0.1',
            port: $parts['port'] ?? 6379,
            password: isset($parts['pass']) ? urldecode($parts['pass']) : null,
            database: isset($parts['path']) ? (int) ltrim($parts['path'], '/') : 0,
        );
    }

    public function toConnectionString(): string
    {
        $auth = $this->password !== null ? ":{$this->password}@" : '';
        $query = http_build_query(array_filter([
            'idle' => $this->idleTimeout,
            'db' => $this->database ?: null,
        ]));

        return "redis://{$auth}{$this->host}:{$this->port}" . ($query ? "?{$query}" : '');
    }
}
