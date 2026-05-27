<?php

declare(strict_types=1);

namespace Phalanx\Substrate;

/**
 * Channel-backed connection pool replacing openswoole/core's ClientPool.
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
        private readonly string $factoryClass,
        private readonly mixed $config,
        private(set) int $size = self::DEFAULT_SIZE,
    ) {
        $this->channel = Substrate::channels()->create($size);
    }

    /**
     * @return T|false
     */
    public function get(float $timeout = -1): mixed
    {
        $channel = $this->channel;
        if ($channel === null) {
            return false;
        }

        if ($channel->isEmpty() && $this->created < $this->size) {
            $this->make();
        }

        $this->active++;

        return $channel->pop($timeout);
    }

    public function put(object $connection): void
    {
        if ($this->channel === null) {
            return;
        }

        $this->channel->push($connection);
        $this->active--;
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

    public function compensateFailedCheckout(): void
    {
        if ($this->active > 0) {
            $this->active--;
        }
    }

    private function make(): void
    {
        $this->created++;
        $client = ($this->factoryClass)::make($this->config);
        $this->channel?->push($client);
    }
}
