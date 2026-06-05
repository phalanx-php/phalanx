<?php

declare(strict_types=1);

namespace Phalanx\Http\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeEventId;

enum HttpEventSid: string implements RuntimeEventId
{
    case BufferEmpty = 'http.buffer.empty';
    case BufferFull = 'http.buffer.full';
    case ClientDisconnected = 'http.client_disconnected';
    case DrainTimeout = 'http.drain_timeout';
    case HttpUpgradeRejected = 'http.http_upgrade_rejected';
    case HttpUpgradeRequested = 'http.http_upgrade_requested';
    case RequestAborted = 'http.request_aborted';
    case RequestFailed = 'http.request_failed';
    case ResponseBodyStarted = 'http.response.body_started';
    case ResponseHeadersStarted = 'http.response.headers_started';
    case ResponseLeaseAbandoned = 'http.response.lease_abandoned';
    case ResponseLeaseAcquired = 'http.response.lease_acquired';
    case ResponseLeaseFulfilled = 'http.response.lease_fulfilled';
    case ResponseWriteFailed = 'http.response.write_failed';
    case RouteMatched = 'http.route_matched';
    case ServerDrainingRejected = 'http.server_draining_rejected';
    case ServerListening = 'http.server_listening';
    case ServerShutdown = 'http.server_shutdown';
    case ServerShutdownInitiated = 'http.server_shutdown_initiated';
    case SseStreamClosed = 'http.sse.stream_closed';
    case SseStreamOpened = 'http.sse.stream_opened';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
