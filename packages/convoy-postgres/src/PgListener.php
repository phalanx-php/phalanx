<?php

declare(strict_types=1);

namespace Convoy\Postgres;

use Amp\Postgres\PostgresListener as AmphpListener;
use Amp\Postgres\PostgresNotification;
use Convoy\Stream\Channel;
use Convoy\Stream\Emitter;

final class PgListener
{
    /** @var array<string, AmphpListener> */
    private array $listeners = [];

    public function __construct(private PgPool $pool) {}

    /** @param non-empty-string $channel */
    public function listen(string $channel): Emitter
    {
        $pool = $this->pool;
        $listeners = &$this->listeners;

        return Emitter::produce(static function (Channel $ch) use ($pool, $channel, &$listeners): void {
            $listener = $pool->listen($channel);
            $listeners[$channel] = $listener;

            try {
                foreach ($listener as $notification) {
                    /** @var PostgresNotification $notification */
                    $ch->emit($notification->payload);
                }
            } finally {
                unset($listeners[$channel]);
            }
        });
    }

    /** @param non-empty-string $channel */
    public function unlisten(string $channel): void
    {
        if (isset($this->listeners[$channel])) {
            $this->listeners[$channel]->unlisten();
            unset($this->listeners[$channel]);
        }
    }

    public function unlistenAll(): void
    {
        foreach ($this->listeners as $listener) {
            $listener->unlisten();
        }
        $this->listeners = [];
    }
}
