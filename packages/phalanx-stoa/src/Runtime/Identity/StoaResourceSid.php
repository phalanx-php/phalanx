<?php

declare(strict_types=1);

namespace Phalanx\Stoa\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

enum StoaResourceSid: string implements RuntimeResourceId
{
    case HttpRequest = 'stoa.http_request';
    case SseStream = 'stoa.sse_stream';
    case WsConnection = 'stoa.ws_connection';
    case OutboundHttpRequest = 'stoa.outbound_http_request';
    case UdpListener = 'stoa.udp_listener';
    case UdpSession = 'stoa.udp_session';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
