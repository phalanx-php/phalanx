<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Routes;

use Phalanx\Stoa\RequestContext;
use Phalanx\Stoa\Sse\SseStream;
use Phalanx\Stoa\Sse\SseStreamFactory;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Scopeable;
use Swoole\Coroutine;

final class Counter implements Scopeable
{
    public function __construct(private readonly SseStreamFactory $streams)
    {
    }

    public function __invoke(RequestContext $ctx): SseStream
    {
        $stream = $this->streams->open($ctx);

        for ($i = 1; $i <= 5; $i++) {
            if ($ctx->isCancelled) {
                $stream->close('cancelled');
                return $stream;
            }

            $stream->writeEvent("tick {$i}", event: 'count', id: (string) $i);
            $ctx->call(
                static function (): bool {
                    Coroutine::sleep(0.1);
                    return true;
                },
                WaitReason::delay(0.1),
            );
        }

        $stream->close();

        return $stream;
    }
}
