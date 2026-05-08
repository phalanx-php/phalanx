<?php

declare(strict_types=1);

namespace Phalanx\Hermes\Runtime\Identity;

use Phalanx\Runtime\Identity\RuntimeEventId;

enum HermesEventSid: string implements RuntimeEventId
{
    case ConnectionAborted = 'hermes.ws.connection_aborted';
    case ConnectionClosed = 'hermes.ws.connection_closed';
    case ConnectionFailed = 'hermes.ws.connection_failed';
    case ConnectionOpened = 'hermes.ws.connection_opened';
    case HandshakeFailed = 'hermes.ws.handshake_failed';
    case PingTimeout = 'hermes.ws.ping_timeout';
    case ServerUpgradeAccepted = 'hermes.ws.server_upgrade_accepted';
    case ServerUpgradeRejected = 'hermes.ws.server_upgrade_rejected';
    case WriteFailed = 'hermes.ws.write_failed';
    case WriteQueueFull = 'hermes.ws.write_queue_full';

    public function key(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }
}
