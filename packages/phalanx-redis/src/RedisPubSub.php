<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Clue\React\Redis\Client;
use Clue\React\Redis\Factory as RedisFactory;
use Phalanx\Stream\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Stream\Emitter;
use React\Promise\Deferred;

use function React\Async\await;

final class RedisPubSub
{
    /** @var list<Client> */
    private array $subscribers = [];

    public function __construct(
        private RedisConfig $config,
    ) {}

    public function subscribe(string ...$channels): Emitter
    {
        $connString = $this->config->toConnectionString();
        $subscribers = &$this->subscribers;

        return Emitter::produce(static function (Channel $ch, StreamContext $ctx) use ($connString, $channels, &$subscribers): void {
            $factory = new RedisFactory();
            $client = $factory->createLazyClient($connString);
            $subscribers[] = $client;
            $done = new Deferred();

            $client->on('message', static function (string $channel, string $message) use ($ch): void {
                $ch->emit(['channel' => $channel, 'message' => $message]);
            });

            $client->on('error', static function (\Throwable $e) use ($ch): void {
                $ch->error($e);
            });

            $client->on('close', static function () use ($done): void {
                $done->resolve(null);
            });

            $ctx->onDispose(static function () use ($client, $done): void {
                $client->close();
                $done->resolve(null);
            });

            foreach ($channels as $channel) {
                $client->__call('subscribe', [$channel]);
            }

            await($done->promise());
        });
    }

    public function close(): void
    {
        foreach ($this->subscribers as $client) {
            $client->close();
        }
        $this->subscribers = [];
    }
}
