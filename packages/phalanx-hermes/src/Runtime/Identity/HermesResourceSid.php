<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeResourceId;

enum HermesResourceSid: string implements RuntimeResourceId
{
    case WebSocketClientConnection = 'hermes.ws.client_connection';
    case WebSocketServerConnection = 'hermes.ws.server_connection';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
