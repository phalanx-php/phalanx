<?php

declare(strict_types=1);

namespace Phalanx\Redis;

use Closure;
use Phalanx\Cancellation\Cancelled;
use Phalanx\Scope\ExecutionScope;
use Phalanx\Styx\Channel;
use Phalanx\Styx\Emitter;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Executable;
use Phalanx\Task\Scopeable;
use Phalanx\Task\Task;
use Throwable;

final class RedisPubSub
{
    /** @var list<\Redis> */
    private array $subscribers = [];

    public function __construct(
        private RedisConfig $config,
    ) {
    }

    public function subscribe(string ...$channels): Emitter
    {
        $config = $this->config;
        $pubsub = $this;

        $producer = static function (
            Channel $ch,
            ExecutionScope $ctx,
        ) use (
            $config,
            $channels,
            $pubsub,
        ): void {
            $client = RedisClientFactory::connect($config);
            $pubsub->addSubscriber($client);

            $ctx->onDispose(static function () use ($client): void {
                $client->close();
            });

            try {
                $ctx->call(
                    static fn(): mixed => $client->subscribe(
                        $channels,
                        static function (\Redis $redis, string $channel, string $message) use ($ch): void {
                            $ch->emit(['channel' => $channel, 'message' => $message]);
                        },
                    ),
                    WaitReason::redis('subscribe'),
                );
            } finally {
                $client->close();
                $pubsub->removeSubscriber($client);
            }
        };

        return Emitter::produce($producer);
    }

    /** @internal */
    public function addSubscriber(\Redis $client): void
    {
        $this->subscribers[] = $client;
    }

    /** @internal */
    public function removeSubscriber(\Redis $client): void
    {
        $this->subscribers = array_values(array_filter(
            $this->subscribers,
            static fn(\Redis $c): bool => $c !== $client,
        ));
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

                    if ($handler instanceof Closure) {
                        return $handler($child);
                    }

                    return $handler->__invoke($child);
                }));
            } catch (Throwable $e) {
                if ($e instanceof Cancelled) {
                    throw $e;
                }
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
