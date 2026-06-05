<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeEventId;

enum WebSocketEventSid: string implements RuntimeEventId
{
    case ConnectionAborted = 'websocket.ws.connection_aborted';
    case ConnectionClosed = 'websocket.ws.connection_closed';
    case ConnectionFailed = 'websocket.ws.connection_failed';
    case ConnectionOpened = 'websocket.ws.connection_opened';
    case HandshakeFailed = 'websocket.ws.handshake_failed';
    case PingTimeout = 'websocket.ws.ping_timeout';
    case ServerUpgradeAccepted = 'websocket.ws.server_upgrade_accepted';
    case ServerUpgradeRejected = 'websocket.ws.server_upgrade_rejected';
    case WriteFailed = 'websocket.ws.write_failed';
    case WriteQueueFull = 'websocket.ws.write_queue_full';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
