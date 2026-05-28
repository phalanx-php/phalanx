<?php

declare(strict_types=1);

namespace AegisSwoole\Postgres;

final readonly class PostgresPoolConfig
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $password,
        public int $size = 5,
    ) {
    }
}
