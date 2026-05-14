<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Routes;

use OpenSwoole\Coroutine;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\ResponseSink;
use Phalanx\Stoa\Sse\SseStream;
use Phalanx\Stoa\Sse\SseStreamFactory;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Scopeable;

final class Counter implements Scopeable
{
    public function __construct(private readonly SseStreamFactory $streams)
    {
    }

    public function __invoke(RequestScope $scope): SseStream
    {
        $target = $scope->service(ResponseSink::class);
        $resource = $scope->requestResource;

        $stream = $this->streams->open($scope, $target->response, $resource, $scope->cancellation());

        for ($i = 1; $i <= 5; $i++) {
            if ($scope->isCancelled) {
                $stream->close('cancelled');
                return $stream;
            }

            $stream->writeEvent("tick {$i}", event: 'count', id: (string) $i);
            $scope->call(
                static function (): bool {
                    Coroutine::usleep(100_000);
                    return true;
                },
                WaitReason::delay(0.1),
            );
        }

        $stream->close();

        return $stream;
    }
}
