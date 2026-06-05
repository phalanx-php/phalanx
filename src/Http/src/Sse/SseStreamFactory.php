<?php

declare(strict_types=1);

namespace Phalanx\Http\Sse;

use Phalanx\Http\RequestContext;
use Phalanx\Http\ResponseSink;
use Phalanx\Http\Runtime\Identity\HttpEventSid;

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
    public function open(RequestContext $ctx): SseStream
    {
        $response = $ctx->service(ResponseSink::class)->response;
        $request = $ctx->service(\Phalanx\Http\RequestResource::class);

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
        $request->event(HttpEventSid::SseStreamOpened);

        return new SseStream($ctx, $response, $request, $ctx->cancellation());
    }
}
