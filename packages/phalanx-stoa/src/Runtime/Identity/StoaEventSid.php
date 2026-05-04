<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeEventId;

enum StoaEventSid: string implements RuntimeEventId
{
    case BufferEmpty = 'stoa.buffer.empty';
    case BufferFull = 'stoa.buffer.full';
    case ClientDisconnected = 'stoa.client_disconnected';
    case DrainTimeout = 'stoa.drain_timeout';
    case RequestAborted = 'stoa.request_aborted';
    case RequestFailed = 'stoa.request_failed';
    case ResponseBodyStarted = 'stoa.response.body_started';
    case ResponseHeadersStarted = 'stoa.response.headers_started';
    case ResponseLeaseAbandoned = 'stoa.response.lease_abandoned';
    case ResponseLeaseAcquired = 'stoa.response.lease_acquired';
    case ResponseLeaseFulfilled = 'stoa.response.lease_fulfilled';
    case ResponseWriteFailed = 'stoa.response.write_failed';
    case RouteMatched = 'stoa.route_matched';
    case ServerDrainingRejected = 'stoa.server_draining_rejected';
    case ServerShutdown = 'stoa.server_shutdown';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
