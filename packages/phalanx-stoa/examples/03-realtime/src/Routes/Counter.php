<?php

declare(strict_types=1);

namespace Acme\StoaDemo\Realtime\Routes;

use OpenSwoole\Coroutine;
use OpenSwoole\Http\Response;
use Phalanx\Stoa\RequestScope;
use Phalanx\Stoa\Runtime\StoaScopeKey;
use Phalanx\Stoa\Sse\SseStream;
use Phalanx\Stoa\Sse\SseStreamFactory;
use Phalanx\Stoa\StoaRequestResource;
use Phalanx\Supervisor\WaitReason;
use Phalanx\Task\Scopeable;
use RuntimeException;

final class Counter implements Scopeable
{
    public function __construct(private readonly SseStreamFactory $streams)
    {
    }

    public function __invoke(RequestScope $scope): SseStream
    {
        $response = $scope->attribute(StoaScopeKey::OpenSwooleResponse->value);
        $resource = $scope->attribute(StoaScopeKey::RequestResource->value);

        if (!$response instanceof Response || !$resource instanceof StoaRequestResource) {
            throw new RuntimeException('Counter requires a live OpenSwoole request target.');
        }

        $stream = $this->streams->open($scope, $response, $resource, $scope->cancellation());

        for ($i = 1; $i <= 5; $i++) {
            if ($scope->isCancelled) {
                $stream->close('cancelled');
                return $stream;
            }

            $stream->writeEvent("tick {$i}", event: 'count', id: (string) $i);
            $scope->call(
                static fn(): bool => Coroutine::usleep(100_000) === null,
                WaitReason::delay(0.1),
            );
        }

        $stream->close();

        return $stream;
    }
}
