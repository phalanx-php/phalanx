<?php

declare(strict_types=1);

namespace Phalanx\WebSocket\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

enum WebSocketResourceSid: string implements RuntimeResourceId
{
    case WebSocketClientConnection = 'websocket.ws.client_connection';
    case WebSocketServerConnection = 'websocket.ws.server_connection';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
