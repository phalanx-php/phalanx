<?php

declare(strict_types=1);

namespace Phalanx\WebSocket;

use Phalanx\Auth\AuthContext;

interface AuthWsContext extends WsContext
{
    public AuthContext $auth { get; }
}
