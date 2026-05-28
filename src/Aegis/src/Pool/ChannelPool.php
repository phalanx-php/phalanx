<?php

declare(strict_types=1);

namespace Phalanx\Pool;

use Phalanx\Runtime\Swoole\SwooleChannel;

/**
 * Channel-backed connection pool for coroutine-local client reuse.
 *
 * @template T of object
 */
final class ChannelPool
{
    public const int DEFAULT_SIZE = 16;

    private(set) int $active = 0;

    private int $created = 0;

    private ?SwooleChannel $channel;

    private bool $closed = false;

    /**
     * @param class-string $factoryClass must implement a static make(mixed): T method
     */
    public function __construct(
        private string $factoryClass,
        private mixed $config,
        private(set) int $size = self::DEFAULT_SIZE,
        ?SwooleChannel $channel = null,
    ) {
        $this->channel = $channel;
    }

    /**
     * @return T|false
     */
    public function get(float $timeout = -1): mixed
    {
        if ($this->closed) {
            return false;
        }

        $channel = $this->ensureChannel();

        if ($channel->isEmpty() && $this->created < $this->size) {
            $this->make();
        }

        $result = $channel->pop($timeout);

        if ($result === false) {
            return false;
        }

        $this->active++;

        return $result;
    }

    public function put(object $connection): void
    {
        if ($this->closed || $this->channel === null) {
            return;
        }

        $this->channel->push($connection);

        if ($this->active > 0) {
            $this->active--;
        }
    }

    public function close(): void
    {
        $this->closed = true;

        $channel = $this->channel;
        if ($channel === null) {
            return;
        }

        while (!$channel->isEmpty()) {
            $client = $channel->pop(0.1);
            if (is_object($client) && method_exists($client, 'close')) {
                $client->close();
            }
        }

        $channel->close();
        $this->channel = null;
        $this->created = 0;
        $this->active = 0;
    }

    private function ensureChannel(): SwooleChannel
    {
        return $this->channel ??= new SwooleChannel($this->size);
    }

    private function make(): void
    {
        $this->created++;

        try {
            $client = ($this->factoryClass)::make($this->config);
            $this->channel?->push($client);
        } catch (\Throwable $e) {
            $this->created--;
            throw $e;
        }
    }
}
