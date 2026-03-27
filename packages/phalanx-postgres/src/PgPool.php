<?php

declare(strict_types=1);

namespace Phalanx\Postgres;

use Amp\Postgres\PostgresConnectionPool;
use Amp\Postgres\PostgresResult;
use Amp\Postgres\PostgresStatement;
use Amp\Postgres\PostgresTransaction;
use Amp\Postgres\PostgresListener;

final class PgPool
{
    private(set) PostgresConnectionPool $inner;

    public function __construct(PgConfig $config)
    {
        $this->inner = new PostgresConnectionPool(
            config: $config->toAmphpConfig(),
            maxConnections: max(1, $config->maxConnections),
            idleTimeout: max(1, $config->idleTimeout),
        );
    }

    public function query(string $sql): PostgresResult
    {
        return $this->inner->query($sql);
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): PostgresResult
    {
        return $this->inner->execute($sql, $params);
    }

    public function prepare(string $sql): PostgresStatement
    {
        return $this->inner->prepare($sql);
    }

    public function beginTransaction(): PostgresTransaction
    {
        return $this->inner->beginTransaction();
    }

    /** @param non-empty-string $channel */
    public function notify(string $channel, string $payload = ''): PostgresResult
    {
        return $this->inner->notify($channel, $payload);
    }

    /**
     * @param non-empty-string $channel
     * @return PostgresListener&\Traversable<int, \Amp\Postgres\PostgresNotification>
     */
    public function listen(string $channel): PostgresListener
    {
        return $this->inner->listen($channel);
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
