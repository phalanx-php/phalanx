<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Closure;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory as RedisFactory;
use Phalanx\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Stream\Contract\StreamContext;
use Phalanx\Styx\Emitter;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use React\Promise\Deferred;



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

            $ctx->await($done->promise());
        });
    }

    public function subscribeEach(
        string $channel,
        Scopeable|Executable|Closure $handler,
        ExecutionScope $scope,
    ): void {
        $emitter = $this->subscribe($channel);

        foreach ($emitter($scope) as $item) {
            try {
                $scope->executeFresh(Task::of(static function (ExecutionScope $child) use ($handler, $item): mixed {
                    $child = $child->withAttribute('subscription.message', $item['message']);
                    $child = $child->withAttribute('subscription.channel', $item['channel']);

                    return ($handler)($child);
                }));
            } catch (\Throwable) {
                // Individual message failure must not kill the subscription loop.
                // Callers needing error visibility should use subscribe() directly.
            }
        }
    }

    public function close(): void
    {
        foreach ($this->subscribers as $client) {
            $client->close();
        }
        $this->subscribers = [];
    }
}
