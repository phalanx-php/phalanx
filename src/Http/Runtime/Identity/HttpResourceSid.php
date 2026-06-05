<?php

declare(strict_types=1);

namespace Phalanx\Http\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

enum HttpResourceSid: string implements RuntimeResourceId
{
    case HttpRequest = 'http.http_request';
    case HttpServer = 'http.http_server';
    case SseStream = 'http.sse_stream';
    case WsConnection = 'http.ws_connection';
    case UdpListener = 'http.udp_listener';
    case UdpSession = 'http.udp_session';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
