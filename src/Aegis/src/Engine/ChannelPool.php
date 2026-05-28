<?php

declare(strict_types=1);

namespace Phalanx\Engine;

/**
 * Channel-backed connection pool replacing the swoole/core ClientPool it replaced.
 *
 * @template T of object
 */
final class ChannelPool
{
    public const int DEFAULT_SIZE = 16;

    private(set) int $active = 0;

    private int $created = 0;

    private ?ChannelHandle $channel;

    /**
     * @param class-string $factoryClass must implement a static make(mixed): T method
     */
    public function __construct(
        private string $factoryClass,
        private mixed $config,
        private(set) int $size = self::DEFAULT_SIZE,
        ?ChannelHandle $channel = null,
    ) {
        $this->channel = $channel;
    }

    /**
     * @return T|false
     */
    public function get(float $timeout = -1): mixed
    {
        $channel = $this->ensureChannel();
        if ($channel === null) {
            return false;
        }

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
        if ($this->channel === null) {
            return;
        }

        $this->channel->push($connection);

        if ($this->active > 0) {
            $this->active--;
        }
    }

    public function close(): void
    {
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

    private function ensureChannel(): ?ChannelHandle
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        if (!Engine::isBooted()) {
            return null;
        }

        $this->channel = Engine::channels()->create($this->size);

        return $this->channel;
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
