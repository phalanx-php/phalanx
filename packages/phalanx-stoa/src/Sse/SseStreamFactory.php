<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Sse;

use OpenSwoole\Http\Response;
use Phalanx\Cancellation\CancellationToken;
use Phalanx\Scope\Suspendable;
use Phalanx\Stoa\Runtime\Identity\StoaEventSid;
use Phalanx\Stoa\StoaRequestResource;

/**
 * Promotes an in-flight HTTP request into a long-lived SSE stream.
 *
 * The factory writes the canonical SSE response headers, acquires a
 * delivery lease against the underlying fd so the supervisor sees the
 * stream as in-flight, and returns a {@see SseStream} the handler can
 * drive at its own cadence. Disconnect cancellation is delivered via the
 * existing CancellationToken on the request resource.
 */
final class SseStreamFactory
{
    public function open(
        Suspendable $scope,
        Response $response,
        StoaRequestResource $request,
        CancellationToken $cancellation,
    ): SseStream {
        if ($request->fd !== null) {
            $request->acquireDeliveryLease($request->fd);
        }

        $response->status(200);
        $response->header('Content-Type', 'text/event-stream');
        $response->header('Cache-Control', 'no-cache');
        $response->header('Connection', 'keep-alive');
        $response->header('X-Accel-Buffering', 'no');

        $request->headersStarted();
        $request->bodyStarted();
        $request->event(StoaEventSid::SseStreamOpened);

        return new SseStream($scope, $response, $request, $cancellation);
    }
}
